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
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Exercises the provision and service use cases through the real command bus
 * against the real MongoDB write model and the real Redis read model —
 * projection included — with no in-memory doubles.
 */
final class ProvisionAndServiceTest extends IntegrationTestCase
{
    /** @var list<int> */
    private const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

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
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->dispatch($this->container, new ProvisionVendingMachine($this->accepted()));

        $stored = $this->repository->find(VendingMachineId::fromString($result->id()));

        self::assertNotNull($stored);
        self::assertSame(
            self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            $stored->state()->acceptedDenominations->denominationsInCents(),
        );
        self::assertTrue($stored->state()->stock->isEmpty());
        self::assertTrue($stored->state()->cashReserve->isEmpty());
        self::assertTrue($stored->state()->insertedFunds->isEmpty());

        self::assertSame(
            [
                'id' => $result->id(),
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [],
                'cashReserve' => [],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($result->id()),
        );
    }

    public function test_service_updates_the_write_model_and_the_projection(): void
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                [
                    ['denominationInCents' => 100, 'quantity' => 5],
                    ['denominationInCents' => 50, 'quantity' => 3],
                ],
                self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            ),
        );

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(10, $stored->state()->stock->quantityOf('WATER'));
        self::assertSame([100 => 5, 50 => 3], $stored->state()->cashReserve->counts());
        self::assertSame(
            self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            $stored->state()->acceptedDenominations->denominationsInCents(),
        );

        self::assertSame(
            [
                'id' => $id,
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                'cashReserve' => [
                    ['denominationInCents' => 100, 'quantity' => 5],
                    ['denominationInCents' => 50, 'quantity' => 3],
                ],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($id),
        );
    }

    public function test_service_replaces_the_previous_state(): void
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [
                    ['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10],
                    ['selectorCode' => 'CHIPS', 'priceInCents' => 120, 'stock' => 4],
                ],
                [['denominationInCents' => 100, 'quantity' => 5]],
                self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            ),
        );

        $this->dispatch(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [['selectorCode' => 'CHIPS', 'priceInCents' => 150, 'stock' => 1]],
                [['denominationInCents' => 50, 'quantity' => 2]],
                [10, 50],
            ),
        );

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(['CHIPS'], array_keys($stored->state()->stock->products()));
        self::assertSame(1, $stored->state()->stock->quantityOf('CHIPS'));
        self::assertSame(150, $stored->state()->stock->products()['CHIPS']->price()->amountInCents());
        self::assertSame([50 => 2], $stored->state()->cashReserve->counts());
        self::assertSame([10, 50], $stored->state()->acceptedDenominations->denominationsInCents());

        $projected = $this->readModel->get($id);

        self::assertNotNull($projected);
        self::assertSame([['selectorCode' => 'CHIPS', 'priceInCents' => 150, 'stock' => 1]], $projected['stock']);
        self::assertSame([['denominationInCents' => 50, 'quantity' => 2]], $projected['cashReserve']);
        self::assertSame([10, 50], $projected['acceptedDenominationsInCents']);
    }

    public function test_service_is_rejected_and_changes_nothing_while_a_transaction_is_pending(): void
    {
        $id = $this->provisionId();
        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));

        $thrown = $this->dispatchExpectingFailure(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                [['denominationInCents' => 100, 'quantity' => 5]],
                self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            ),
        );

        self::assertInstanceOf(PendingTransactionInProgressException::class, $thrown);
        self::assertStringContainsString('transaction is in progress', $thrown->getMessage());

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertTrue($stored->state()->stock->isEmpty());
        self::assertTrue($stored->state()->cashReserve->isEmpty());

        self::assertSame(
            [
                'id' => $id,
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [],
                'cashReserve' => [],
                'insertedFundsInCents' => 25,
            ],
            $this->readModel->get($id),
        );
    }

    private function provisionId(): string
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->dispatch($this->container, new ProvisionVendingMachine($this->accepted()));

        return $result->id();
    }

    private function accepted(): AcceptedDenominations
    {
        return AcceptedDenominations::fromCents(self::ACCEPTED_DENOMINATIONS_IN_CENTS);
    }
}
