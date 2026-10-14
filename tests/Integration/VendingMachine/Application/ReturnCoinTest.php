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
use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\ReturnCoin\ReturnCoinResult;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Exercises returning inserted funds through the real command bus against the
 * real MongoDB write model and the real Redis read model — projection
 * included — with no in-memory doubles.
 */
final class ReturnCoinTest extends IntegrationTestCase
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

    public function test_returning_hands_back_the_inserted_coins_and_resets_both_stores(): void
    {
        $id = $this->provisionId();

        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));
        $this->dispatch($this->container, InsertCoin::fromInput($id, 10));
        $this->dispatch($this->container, InsertCoin::fromInput($id, 25));

        /** @var ReturnCoinResult $result */
        $result = $this->dispatch($this->container, ReturnCoin::fromInput($id));

        self::assertSame([25, 10, 25], $result->returnedCoinsInCents);
        self::assertSame(0, $result->state->insertedFundsInCents);

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertTrue($stored->state()->insertedFunds->isEmpty());

        self::assertSame(
            [
                'id' => $id,
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [],
                'cashReserve' => [],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($id),
        );
    }

    public function test_returning_with_nothing_inserted_is_a_no_op(): void
    {
        $id = $this->provisionId();

        /** @var ReturnCoinResult $result */
        $result = $this->dispatch($this->container, ReturnCoin::fromInput($id));

        self::assertSame([], $result->returnedCoinsInCents);
        self::assertSame(0, $result->state->insertedFundsInCents);

        self::assertSame(
            [
                'id' => $id,
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [],
                'cashReserve' => [],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($id),
        );
    }

    public function test_returning_from_an_unknown_machine_is_rejected(): void
    {
        $thrown = $this->dispatchExpectingFailure(
            $this->container,
            ReturnCoin::fromInput(VendingMachineId::generate()->toString()),
        );

        self::assertInstanceOf(VendingMachineNotFoundException::class, $thrown);
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
