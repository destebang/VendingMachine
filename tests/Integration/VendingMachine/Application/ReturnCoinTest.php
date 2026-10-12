<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\InsertCoin\InsertCoinHandler;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineHandler;
use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\ReturnCoin\ReturnCoinHandler;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises returning inserted funds against the real MongoDB write model and
 * the real Redis read model, with no in-memory doubles.
 */
final class ReturnCoinTest extends TestCase
{
    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    private ProvisionVendingMachineHandler $provision;

    private InsertCoinHandler $insertCoin;

    private ReturnCoinHandler $returnCoin;

    protected function setUp(): void
    {
        $this->repository = new MongoVendingMachineRepository(
            DocumentManagerFactory::create(),
            new DomainEventCollector(),
        );
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $this->provision = new ProvisionVendingMachineHandler($this->repository, $this->readModel);
        $this->insertCoin = new InsertCoinHandler($this->repository, $this->readModel);
        $this->returnCoin = new ReturnCoinHandler($this->repository, $this->readModel);
    }

    public function test_returning_hands_back_the_inserted_coins_and_resets_both_stores(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->insertCoin)(InsertCoin::fromInput($id, 25));
        ($this->insertCoin)(InsertCoin::fromInput($id, 10));
        ($this->insertCoin)(InsertCoin::fromInput($id, 25));

        $result = ($this->returnCoin)(ReturnCoin::fromInput($id));

        self::assertSame([25, 10, 25], $result->returnedCoinsInCents);
        self::assertSame(0, $result->state->insertedFundsInCents);

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertTrue($stored->state()->insertedFunds->isEmpty());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 0],
            $this->readModel->get($id),
        );
    }

    public function test_returning_with_nothing_inserted_is_a_no_op(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        $result = ($this->returnCoin)(ReturnCoin::fromInput($id));

        self::assertSame([], $result->returnedCoinsInCents);
        self::assertSame(0, $result->state->insertedFundsInCents);

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 0],
            $this->readModel->get($id),
        );
    }

    public function test_returning_from_an_unknown_machine_is_rejected(): void
    {
        $this->expectException(VendingMachineNotFoundException::class);

        ($this->returnCoin)(ReturnCoin::fromInput(VendingMachineId::generate()->toString()));
    }
}
