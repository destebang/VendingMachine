<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\DocumentManagerFactory;
use App\Kernel\IntegrationEvent\AmqpTransportFactory;
use App\Kernel\IntegrationEvent\JsonEnvelopeSerializer;
use App\Kernel\IntegrationEvent\MessengerIntegrationEventBus;
use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use App\Shared\Application\IntegrationEvent\IntegrationEventBus;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\VendingMachine\Application\IntegrationEvent\ItemVendedV1;
use App\VendingMachine\Application\IntegrationEventTranslator\VendingMachineIntegrationEventTranslator;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Console\OutboxRelayCommand;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\Persistence\Mongo\VendingMachineDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;

/**
 * Exercises the outbox relay end to end: real MongoDB for the outbox, real
 * RabbitMQ for the integration exchange. The test listens on its own queue
 * bound to the `vending_machine.#` routing keys, so anything the relay publishes to
 * `vending_machine.integration` is seen — and anything it does not publish is proven
 * by the queue staying empty.
 */
final class OutboxRelayTest extends TestCase
{
    private DocumentManager $documentManager;

    private JsonEnvelopeSerializer $serializer;

    private AmqpTransport $testQueue;

    protected function setUp(): void
    {
        $this->documentManager = DocumentManagerFactory::create();
        $this->serializer = new JsonEnvelopeSerializer([ItemVendedV1::TYPE => ItemVendedV1::class]);

        // The relay scans the whole collection, so clear outbox entries left
        // behind by other tests: only this test's events may be pending.
        $this->documentManager->getDocumentCollection(VendingMachineDocument::class)->updateMany([], ['$unset' => [
            'pendingEvents' => '',
        ]]);

        // Declared up front, so the binding exists before the relay publishes.
        $this->testQueue = AmqpTransportFactory::createForQueue(
            $this->serializer,
            'test.outbox-relay.' . bin2hex(random_bytes(6)),
            ['vending_machine.#'],
        );
        $this->testQueue->setup();
    }

    public function test_the_relay_publishes_exactly_one_item_vended_message_and_empties_the_outbox(): void
    {
        $id = $this->vendAnItem();
        $pending = $this->pendingEvents($id);

        self::assertCount(4, $pending);

        /** @var array{eventId: string, occurredAt: string, aggregateVersion: int} $itemVendedEntry */
        $itemVendedEntry = $pending[3];

        $exitCode = $this->relay($this->workingBus());

        self::assertSame(Command::SUCCESS, $exitCode);

        // Exactly one message arrived, on the item_vended routing key.
        self::assertSame(1, $this->testQueue->getMessageCount());

        $envelopes = iterator_to_array($this->testQueue->get(), preserve_keys: false);

        self::assertCount(1, $envelopes);

        $message = $envelopes[0]->getMessage();

        self::assertInstanceOf(ItemVendedV1::class, $message);
        self::assertSame($itemVendedEntry['eventId'], $message->id);
        self::assertSame($itemVendedEntry['eventId'], $message->correlationId);
        self::assertSame($id, $message->aggregateId);
        self::assertSame($itemVendedEntry['aggregateVersion'], $message->aggregateVersion);
        self::assertSame($itemVendedEntry['occurredAt'], $message->occurredAt->format(\DateTimeInterface::RFC3339));
        self::assertSame(
            [
                'vendingMachineId' => $id,
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'changeInCents' => [25, 10],
            ],
            $message->data(),
        );

        $this->testQueue->ack($envelopes[0]);

        // Every entry left the outbox: the vended item was published, the rest
        // were dropped without being published (the queue saw nothing else).
        self::assertSame([], $this->pendingEvents($id));
        self::assertSame(0, $this->testQueue->getMessageCount());
    }

    public function test_events_outside_the_published_language_are_removed_without_being_published(): void
    {
        $id = VendingMachineId::generate()->toString();
        $this->repository()->save(VendingMachine::create(
            VendingMachineId::fromString($id),
            AcceptedDenominations::fromCents([5, 10, 25, 100]),
        ));

        self::assertCount(1, $this->pendingEvents($id));

        $this->relay($this->workingBus());

        self::assertSame([], $this->pendingEvents($id));
        self::assertSame(0, $this->testQueue->getMessageCount());
    }

    public function test_a_failed_publish_stays_pending_and_is_retried_on_the_next_poll(): void
    {
        $id = $this->vendAnItem();

        $failingBus = new class implements IntegrationEventBus {
            public function publish(IntegrationEvent ...$events): void
            {
                throw new RuntimeException('The broker is unreachable.');
            }
        };

        $exitCode = $this->relay($failingBus);

        self::assertSame(Command::SUCCESS, $exitCode);

        // The vended item is still pending: the entries before it were
        // dropped, but the failed publish kept its entry for the next poll.
        $pending = $this->pendingEvents($id);

        self::assertCount(1, $pending);
        self::assertSame(4, $pending[0]['aggregateVersion']);

        // The next poll retries the same entry and now it goes through.
        $this->relay($this->workingBus());

        self::assertSame([], $this->pendingEvents($id));

        $envelopes = iterator_to_array($this->testQueue->get(), preserve_keys: false);

        self::assertCount(1, $envelopes);
        self::assertInstanceOf(ItemVendedV1::class, $envelopes[0]->getMessage());

        $this->testQueue->ack($envelopes[0]);
    }

    /**
     * Drives a machine through a full purchase — provision, service, insert
     * 100 cents, select WATER — leaving four entries in its outbox, the last
     * of them the `ItemVended`.
     */
    private function vendAnItem(): string
    {
        $repository = $this->repository();
        $id = VendingMachineId::generate();

        $machine = VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100]));
        $repository->save($machine);

        $machine->service(
            self::waterStock(),
            self::changeReserve(),
            AcceptedDenominations::fromCents([5, 10, 25, 100]),
        );
        $repository->save($machine);

        $machine->insertCoin(Denomination::of(Money::fromCents(100)));
        $repository->save($machine);

        $machine->selectItem('WATER');
        $repository->save($machine);

        return $id->toString();
    }

    private function repository(): MongoVendingMachineRepository
    {
        return new MongoVendingMachineRepository($this->documentManager, new DomainEventCollector());
    }

    private function workingBus(): MessengerIntegrationEventBus
    {
        return new MessengerIntegrationEventBus(AmqpTransportFactory::create($this->serializer));
    }

    private function relay(IntegrationEventBus $bus): int
    {
        $tester = new CommandTester(
            new OutboxRelayCommand($this->documentManager, $bus, new VendingMachineIntegrationEventTranslator()),
        );

        return $tester->execute(['--once' => true]);
    }

    /**
     * The raw outbox entries of one machine, in stored order.
     *
     * @return list<array{eventId: string, type: string, payload: array<string, mixed>, aggregateVersion: int, occurredAt: string}>
     */
    private function pendingEvents(string $id): array
    {
        /** @var array{pendingEvents: list<array{eventId: string, type: string, payload: array<string, mixed>, aggregateVersion: int, occurredAt: string}>}|null $document */
        $document = $this->documentManager
            ->getDocumentCollection(VendingMachineDocument::class)
            ->findOne(['_id' => $id]);

        self::assertNotNull($document);

        return $document['pendingEvents'];
    }

    private static function waterStock(): Stock
    {
        return Stock::fromProducts([
            Product::create('WATER', Money::fromCents(65), 10),
        ]);
    }

    private static function changeReserve(): CashReserve
    {
        return CashReserve::fromCounts([25 => 40, 10 => 40, 5 => 40]);
    }
}
