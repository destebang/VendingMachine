<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\RestockVendingMachine\RestockVendingMachine;
use App\VendingMachine\Application\RestockVendingMachine\RestockVendingMachineHandler;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the provision and service use cases against the real MongoDB write
 * model and the real Redis read model, with no in-memory doubles.
 */
final class ProvisionAndRestockTest extends TestCase
{
    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    private ProvisionVendingMachineHandler $provision;

    private RestockVendingMachineHandler $restock;

    protected function setUp(): void
    {
        $this->repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $this->provision = new ProvisionVendingMachineHandler($this->repository, $this->readModel);
        $this->restock = new RestockVendingMachineHandler($this->repository, $this->readModel);
    }

    public function test_provisioning_persists_an_empty_machine_and_its_projection(): void
    {
        $result = ($this->provision)(new ProvisionVendingMachine());

        $stored = $this->repository->find(VendingMachineId::fromString($result->id()));

        self::assertNotNull($stored);
        self::assertSame([], $stored->stock());
        self::assertSame([], $stored->change());
        self::assertTrue($stored->balance()->isZero());

        self::assertSame(
            ['id' => $result->id(), 'stock' => [], 'change' => [], 'balanceInCents' => 0],
            $this->readModel->get($result->id()),
        );
    }

    public function test_restock_updates_the_write_model_and_the_projection(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->restock)(new RestockVendingMachine(
            VendingMachineId::fromString($id),
            ['WATER' => Product::create('WATER', Money::fromCents(65), stock: 10)],
            [100 => 5, 50 => 3],
        ));

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(10, $stored->stock()['WATER']->stock());
        self::assertSame([100 => 5, 50 => 3], $stored->change());

        self::assertSame([
            'id' => $id,
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'change' => [
                ['denominationInCents' => 100, 'quantity' => 5],
                ['denominationInCents' => 50, 'quantity' => 3],
            ],
            'balanceInCents' => 0,
        ], $this->readModel->get($id));
    }

    public function test_restock_replaces_the_previous_state(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->restock)(new RestockVendingMachine(
            VendingMachineId::fromString($id),
            [
                'WATER' => Product::create('WATER', Money::fromCents(65), stock: 10),
                'CHIPS' => Product::create('CHIPS', Money::fromCents(120), stock: 4),
            ],
            [100 => 5],
        ));

        ($this->restock)(new RestockVendingMachine(
            VendingMachineId::fromString($id),
            ['CHIPS' => Product::create('CHIPS', Money::fromCents(150), stock: 1)],
            [50 => 2],
        ));

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(['CHIPS'], array_keys($stored->stock()));
        self::assertSame(1, $stored->stock()['CHIPS']->stock());
        self::assertSame(150, $stored->stock()['CHIPS']->price()->amountInCents());
        self::assertSame([50 => 2], $stored->change());

        $projected = $this->readModel->get($id);

        self::assertSame(
            [['selectorCode' => 'CHIPS', 'priceInCents' => 150, 'stock' => 1]],
            $projected['stock'],
        );
        self::assertSame([['denominationInCents' => 50, 'quantity' => 2]], $projected['change']);
    }

    public function test_restock_is_rejected_and_changes_nothing_while_a_transaction_is_pending(): void
    {
        $id = VendingMachineId::generate();
        $machine = VendingMachine::reconstitute($id, Money::fromCents(50), 0, [], []);

        $this->repository->save($machine);
        $this->readModel->put($id->toString(), MachineStateView::fromAggregate($machine)->toArray());

        try {
            ($this->restock)(new RestockVendingMachine(
                $id,
                ['WATER' => Product::create('WATER', Money::fromCents(65), stock: 10)],
                [100 => 5],
            ));
            self::fail('Expected the service action to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException) {
            // expected
        }

        $stored = $this->repository->find($id);
        self::assertNotNull($stored);
        self::assertSame([], $stored->stock());
        self::assertSame([], $stored->change());

        self::assertSame(
            ['id' => $id->toString(), 'stock' => [], 'change' => [], 'balanceInCents' => 50],
            $this->readModel->get($id->toString()),
        );
    }
}
