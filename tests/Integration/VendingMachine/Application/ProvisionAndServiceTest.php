<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachineHandler;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the provision and service use cases against the real MongoDB write
 * model and the real Redis read model, with no in-memory doubles.
 */
final class ProvisionAndServiceTest extends TestCase
{
    /** @var list<int> */
    private const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    private ProvisionVendingMachineHandler $provision;

    private ServiceVendingMachineHandler $service;

    protected function setUp(): void
    {
        $this->repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $this->provision = new ProvisionVendingMachineHandler($this->repository, $this->readModel);
        $this->service = new ServiceVendingMachineHandler($this->repository, $this->readModel);
    }

    public function test_provisioning_persists_an_empty_machine_and_its_projection(): void
    {
        $result = ($this->provision)(new ProvisionVendingMachine($this->accepted()));

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
        $id = ($this->provision)(new ProvisionVendingMachine($this->accepted()))->id();

        ($this->service)(
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
                CashReserve::fromCounts([100 => 5, 50 => 3]),
                $this->accepted(),
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
        $id = ($this->provision)(new ProvisionVendingMachine($this->accepted()))->id();

        ($this->service)(
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([
                    Product::create('WATER', Money::fromCents(65), 10),
                    Product::create('CHIPS', Money::fromCents(120), 4),
                ]),
                CashReserve::fromCounts([100 => 5]),
                $this->accepted(),
            ),
        );

        ($this->service)(
            new ServiceVendingMachine(
                VendingMachineId::fromString($id),
                Stock::fromProducts([Product::create('CHIPS', Money::fromCents(150), 1)]),
                CashReserve::fromCounts([50 => 2]),
                AcceptedDenominations::fromCents([10, 50]),
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
        $id = VendingMachineId::generate();
        $machine = VendingMachine::create($id, $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $this->repository->save($machine);
        $this->readModel->put($id->toString(), MachineStateView::fromAggregate($machine)->toArray());

        try {
            ($this->service)(
                new ServiceVendingMachine(
                    $id,
                    Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
                    CashReserve::fromCounts([100 => 5]),
                    $this->accepted(),
                ),
            );
            self::fail('Expected the service action to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException $exception) {
            self::assertStringContainsString('transaction is in progress', $exception->getMessage());
        }

        $stored = $this->repository->find($id);
        self::assertNotNull($stored);
        self::assertTrue($stored->state()->stock->isEmpty());
        self::assertTrue($stored->state()->cashReserve->isEmpty());

        self::assertSame(
            [
                'id' => $id->toString(),
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [],
                'cashReserve' => [],
                'insertedFundsInCents' => 25,
            ],
            $this->readModel->get($id->toString()),
        );
    }

    private function accepted(): AcceptedDenominations
    {
        return AcceptedDenominations::fromCents(self::ACCEPTED_DENOMINATIONS_IN_CENTS);
    }
}
