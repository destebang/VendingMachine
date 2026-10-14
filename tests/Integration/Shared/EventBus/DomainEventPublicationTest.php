<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\EventBus;

use App\Kernel\ContainerFactory;
use App\Kernel\RedisClientFactory;
use App\Tests\Integration\IntegrationTestCase;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use Predis\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Drives write commands through the real command bus — publish middleware,
 * domain event bus and read-model projector included — and asserts the
 * publication rules of the domain events, using the Redis projection (the one
 * subscriber) and Redis' own command statistics as the observables.
 */
final class DomainEventPublicationTest extends IntegrationTestCase
{
    /** @var list<int> */
    private const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

    private ContainerBuilder $container;

    private RedisVendingMachineStateReadModelRepository $readModel;

    private ClientInterface $redis;

    protected function setUp(): void
    {
        $this->container = ContainerFactory::create();
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $this->redis = RedisClientFactory::create();
    }

    /**
     * The exact-change rejection records `InsertedFundsReturnedDueToInsufficientChange`
     * and then throws. The command fails — but the write had committed, so the
     * event is still published and the projection still reflects the refunded
     * balance.
     */
    public function test_a_command_that_rejects_after_mutating_still_publishes_its_events(): void
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                [],
                self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            ),
        );
        $this->dispatch($this->container, InsertCoin::fromInput($id, 100));

        $thrown = $this->dispatchExpectingFailure($this->container, SelectItem::fromInput($id, 'WATER'));

        self::assertInstanceOf(InsufficientChangeAvailableException::class, $thrown);

        self::assertSame(
            [
                'id' => $id,
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                'cashReserve' => [],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($id),
            'The refund event must have been projected even though the command was rejected.',
        );
    }

    /**
     * A denomination the machine does not accept is rejected before any
     * mutation, so no event is recorded — and none may be published. The one
     * subscriber writes the projection with Redis `SET`, so the server's `SET`
     * counter must stand still (a spurious event carrying an unchanged state
     * would still write, and would be caught here).
     */
    public function test_a_command_that_changes_nothing_publishes_nothing(): void
    {
        $id = $this->provisionId();

        $setCallsBefore = $this->redisSetCalls();
        $projectionBefore = $this->readModel->get($id);

        $thrown = $this->dispatchExpectingFailure($this->container, InsertCoin::fromInput($id, 7));

        self::assertInstanceOf(InvalidDenominationException::class, $thrown);
        self::assertSame(
            $setCallsBefore,
            $this->redisSetCalls(),
            'No event may be published for a no-change rejection.',
        );
        self::assertSame($projectionBefore, $this->readModel->get($id));
    }

    private function provisionId(): string
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->dispatch(
            $this->container,
            new ProvisionVendingMachine(\App\VendingMachine\Domain\Model\AcceptedDenominations::fromCents(
                self::ACCEPTED_DENOMINATIONS_IN_CENTS,
            )),
        );

        return $result->id();
    }

    /**
     * The number of Redis `SET` commands the server has served so far.
     */
    private function redisSetCalls(): int
    {
        /** @var array{Commandstats?: array<string, string>} $info */
        $info = $this->redis->executeCommand($this->redis->createCommand('INFO', ['commandstats']));

        $stats = $info['Commandstats']['cmdstat_set'] ?? '';

        $matches = [];
        if (preg_match('/calls=(\d+)/', $stats, $matches) !== 1) {
            self::fail(sprintf('Could not read the Redis SET command statistics ("%s").', $stats));
        }

        return (int) $matches[1];
    }
}
