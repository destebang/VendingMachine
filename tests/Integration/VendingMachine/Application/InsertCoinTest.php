<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\VendingMachine\Application\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\InsertCoin\InsertCoinHandler;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineHandler;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises coin insertion against the real MongoDB write model and the real
 * Redis read model, with no in-memory doubles.
 */
final class InsertCoinTest extends TestCase
{
    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    private ProvisionVendingMachineHandler $provision;

    private InsertCoinHandler $insertCoin;

    protected function setUp(): void
    {
        $this->repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $this->provision = new ProvisionVendingMachineHandler($this->repository, $this->readModel);
        $this->insertCoin = new InsertCoinHandler($this->repository, $this->readModel);
    }

    public function test_inserted_coins_accumulate_in_the_write_model_and_the_projection(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->insertCoin)(InsertCoin::fromInput($id, 25));
        ($this->insertCoin)(InsertCoin::fromInput($id, 25));
        ($this->insertCoin)(InsertCoin::fromInput($id, 100));

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(150, $stored->state()->insertedFunds->amount()->amountInCents());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 150],
            $this->readModel->get($id),
        );
    }

    public function test_a_rejected_denomination_changes_neither_the_write_model_nor_the_projection(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->insertCoin)(InsertCoin::fromInput($id, 25));

        try {
            ($this->insertCoin)(InsertCoin::fromInput($id, 50));
            self::fail('Expected the inserted coin to be rejected.');
        } catch (InvalidDenominationException) {
            // expected
        }

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(25, $stored->state()->insertedFunds->amount()->amountInCents());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 25],
            $this->readModel->get($id),
        );
    }

    public function test_inserting_into_an_unknown_machine_is_rejected(): void
    {
        $this->expectException(VendingMachineNotFoundException::class);

        ($this->insertCoin)(InsertCoin::fromInput(VendingMachineId::generate()->toString(), 25));
    }
}
