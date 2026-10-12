<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\ContainerFactory;
use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\Tests\Integration\IntegrationTestCase;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Exercises the provision and service use cases against the real command bus,
 * MongoDB write model and Redis read model, with no in-memory doubles.
 */
final class ProvisionAndServiceTest extends IntegrationTestCase
{
    private ContainerBuilder $container;

    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    protected function setUp(): void
    {
        $this->container = ContainerFactory::create();
        $this->repository = new MongoVendingMachineRepository(
            DocumentManagerFactory::create(),
            new DomainEventCollector(),
        );
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
    }

    public function test_provisioning_persists_an_empty_machine_and_its_projection(): void
    {
        $id = $this->provisionId();

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertTrue($stored->state()->stock->isEmpty());
        self::assertTrue($stored->state()->cashReserve->isEmpty());
        self::assertTrue($stored->state()->insertedFunds->isEmpty());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 0],
            $this->readModel->get($id)?->toArray(),
        );
    }

    public function test_service_updates_the_write_model_and_the_projection(): void
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
                CashReserve::fromCounts([100 => 5, 50 => 3]),
            ),
        );

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(10, $stored->state()->stock->quantityOf('WATER'));
        self::assertSame([100 => 5, 50 => 3], $stored->state()->cashReserve->counts());

        self::assertSame(
            [
                'id' => $id,
                'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                'cashReserve' => [
                    ['denominationInCents' => 100, 'quantity' => 5],
                    ['denominationInCents' => 50, 'quantity' => 3],
                ],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($id)?->toArray(),
        );
    }

    public function test_service_replaces_the_previous_state(): void
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([
                    Product::create('WATER', Money::fromCents(65), 10),
                    Product::create('CHIPS', Money::fromCents(120), 4),
                ]),
                CashReserve::fromCounts([100 => 5]),
            ),
        );

        $this->dispatch(
            $this->container,
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([Product::create('CHIPS', Money::fromCents(150), 1)]),
                CashReserve::fromCounts([50 => 2]),
            ),
        );

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(['CHIPS'], array_keys($stored->state()->stock->products()));
        self::assertSame(1, $stored->state()->stock->quantityOf('CHIPS'));
        self::assertSame(150, $stored->state()->stock->products()['CHIPS']->price()->amountInCents());
        self::assertSame([50 => 2], $stored->state()->cashReserve->counts());

        $projected = $this->readModel->get($id);

        self::assertNotNull($projected);
        self::assertSame([['selectorCode' => 'CHIPS', 'priceInCents' => 150, 'stock' => 1]], $projected->stock);
        self::assertSame([['denominationInCents' => 50, 'quantity' => 2]], $projected->cashReserve);
    }

    public function test_service_is_rejected_and_changes_nothing_while_a_transaction_is_pending(): void
    {
        $id = $this->provisionId();
        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));

        $thrown = $this->dispatchExpectingFailure(
            $this->container,
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
                CashReserve::fromCounts([100 => 5]),
            ),
        );

        self::assertInstanceOf(PendingTransactionInProgressException::class, $thrown);

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertTrue($stored->state()->stock->isEmpty());
        self::assertTrue($stored->state()->cashReserve->isEmpty());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 25],
            $this->readModel->get($id)?->toArray(),
        );
    }

    private function provisionId(): string
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->dispatch($this->container, new ProvisionVendingMachine());

        return $result->id();
    }
}
