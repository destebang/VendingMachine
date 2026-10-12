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
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Exercises coin insertion against the real command bus, MongoDB write model and
 * Redis read model, with no in-memory doubles.
 */
final class InsertCoinTest extends IntegrationTestCase
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

    public function test_inserted_coins_accumulate_in_the_write_model_and_the_projection(): void
    {
        $id = $this->provisionId();

        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));
        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));
        $this->dispatch($this->container, InsertCoin::fromInput($id, 100));

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(150, $stored->state()->insertedFunds->amount()->amountInCents());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 150],
            $this->readModel->get($id)?->toArray(),
        );
    }

    public function test_a_rejected_denomination_changes_neither_the_write_model_nor_the_projection(): void
    {
        $id = $this->provisionId();

        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));

        $thrown = $this->dispatchExpectingFailure($this->container, InsertCoin::fromInput($id, 50));

        self::assertInstanceOf(InvalidDenominationException::class, $thrown);
        self::assertStringContainsString('not an allowed denomination', $thrown->getMessage());

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(25, $stored->state()->insertedFunds->amount()->amountInCents());

        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 25],
            $this->readModel->get($id)?->toArray(),
        );
    }

    public function test_inserting_into_an_unknown_machine_is_rejected(): void
    {
        $thrown = $this->dispatchExpectingFailure(
            $this->container,
            InsertCoin::fromInput(VendingMachineId::generate()->toString(), 25),
        );

        self::assertInstanceOf(VendingMachineNotFoundException::class, $thrown);
    }

    private function provisionId(): string
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->dispatch($this->container, new ProvisionVendingMachine());

        return $result->id();
    }
}
