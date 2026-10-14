<?php

declare(strict_types=1);

namespace App\Tests\Integration\Sales\Infrastructure;

use App\Kernel\ContainerFactory;
use App\Kernel\DocumentManagerFactory;
use App\Kernel\IntegrationEvent\AmqpTransportFactory;
use App\Kernel\IntegrationEvent\JsonEnvelopeSerializer;
use App\Kernel\IntegrationEvent\MessengerCommandsFactory;
use App\Kernel\IntegrationEvent\MessengerIntegrationEventBus;
use App\Sales\Application\IntegrationEvent\ItemVendedV1Received;
use App\Sales\Infrastructure\Persistence\Mongo\SaleDocument;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\VendingMachine\Application\IntegrationEvent\ItemVendedV1;
use App\VendingMachine\Application\IntegrationEventTranslator\VendingMachineIntegrationEventTranslator;
use App\VendingMachine\Domain\Event\ItemVended;
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
use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Exercises the Sales consumer end to end: the real outbox relay publishes to
 * RabbitMQ, the real `sales.item_vended` queue carries the message, and the
 * real worker (through the hand-wired container) registers the Sale in
 * MongoDB. Also proves the two guarantees the consumer rests on: the same
 * message delivered twice registers one Sale, and a message whose handling
 * keeps failing is dead-lettered once its retries are exhausted.
 */
final class SalesConsumerTest extends TestCase
{
    private const string SALES_QUEUE = 'sales.item_vended';

    private const string FAILURE_EXCHANGE = 'sales.item_vended.dlx';

    private const string FAILURE_QUEUE = 'sales.item_vended.dlq';

    private DocumentManager $documentManager;

    private JsonEnvelopeSerializer $salesSerializer;

    private AmqpTransport $salesTransport;

    private AmqpTransport $failureTransport;

    protected function setUp(): void
    {
        $this->documentManager = DocumentManagerFactory::create();
        $this->salesSerializer = new JsonEnvelopeSerializer([
            ItemVendedV1Received::TYPE => ItemVendedV1Received::class,
        ]);

        // Declared up front, so the binding exists before the relay publishes.
        $this->salesTransport = AmqpTransportFactory::createForQueue(
            $this->salesSerializer,
            self::SALES_QUEUE,
            [ItemVendedV1Received::TYPE],
        );
        $this->failureTransport = AmqpTransportFactory::createFailureTransport(
            $this->salesSerializer,
            self::FAILURE_EXCHANGE,
            self::FAILURE_QUEUE,
        );

        // Each test starts from a clean slate: no sales, no pending outbox
        // entries (the relay scans the whole collection), no queued messages.
        $this->documentManager->getDocumentCollection(SaleDocument::class)->deleteMany([]);
        $this->documentManager->getDocumentCollection(VendingMachineDocument::class)->updateMany([], ['$unset' => [
            'pendingEvents' => '',
        ]]);
        $this->drain($this->salesTransport);
        $this->drain($this->failureTransport);
    }

    public function test_vending_an_item_registers_exactly_one_sale(): void
    {
        $vendingMachineId = $this->vendAnItem();
        $sourceEventId = $this->pendingItemVendedEventId($vendingMachineId);

        $this->relay();
        $this->consumeSales(1);

        $sales = $this->sales();

        self::assertCount(1, $sales);
        self::assertSame($vendingMachineId, $sales[0]['vendingMachineId']);
        self::assertSame('WATER', $sales[0]['selectorCode']);
        self::assertSame(65, $sales[0]['priceInCents']);
        self::assertSame($sourceEventId, $sales[0]['sourceEventId']);
        self::assertNotEmpty($sales[0]['_id']);
        self::assertNotEmpty($sales[0]['soldAt']);
    }

    public function test_redelivering_the_same_message_registers_no_second_sale(): void
    {
        $message = $this->anItemVendedMessage('01a12f94-62ac-771b-a72a-f8a35ea379bb');

        $this->publishToSales($message);
        $this->publishToSales($message);

        $this->consumeSales(2);

        $sales = $this->sales();

        self::assertCount(1, $sales);
        self::assertSame($message->id, $sales[0]['sourceEventId']);
    }

    public function test_a_message_that_cannot_be_processed_is_dead_lettered_after_retries(): void
    {
        $message = $this->anItemVendedMessage('01a12f94-62ac-771b-a72a-f8a35ea379bb');

        $this->publishToSales($message);

        // Three attempts (the first delivery plus two retries) all fail.
        $this->runFailingWorker(attempts: 3);

        $deadLettered = $this->drain($this->failureTransport);

        self::assertCount(1, $deadLettered);

        $deadLetter = $deadLettered[0]->getMessage();

        self::assertInstanceOf(ItemVendedV1Received::class, $deadLetter);
        self::assertSame($message->id, $deadLetter->id);

        // And nothing was registered.
        self::assertSame([], $this->sales());
    }

    /**
     * Drives a machine through a full purchase — provision, service, insert
     * 100 cents, select WATER — leaving the `ItemVended` event in its outbox.
     */
    private function vendAnItem(): string
    {
        $repository = new MongoVendingMachineRepository($this->documentManager, new DomainEventCollector());
        $id = VendingMachineId::generate();

        $machine = VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100]));
        $repository->save($machine);

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([25 => 40, 10 => 40, 5 => 40]),
            AcceptedDenominations::fromCents([5, 10, 25, 100]),
        );
        $repository->save($machine);

        $machine->insertCoin(Denomination::of(Money::fromCents(100)));
        $repository->save($machine);

        $machine->selectItem('WATER');
        $repository->save($machine);

        return $id->toString();
    }

    /**
     * The id of the pending `ItemVended` outbox entry — the `sourceEventId` the
     * relay will publish as the envelope id, and the Sale must record.
     */
    private function pendingItemVendedEventId(string $vendingMachineId): string
    {
        /** @var array{pendingEvents: list<array{eventId: string, type: string}>}|null $document */
        $document = $this->documentManager
            ->getDocumentCollection(VendingMachineDocument::class)
            ->findOne(['_id' => $vendingMachineId]);

        self::assertNotNull($document);

        foreach ($document['pendingEvents'] as $entry) {
            if ($entry['type'] === ItemVended::class) {
                return $entry['eventId'];
            }
        }

        self::fail('The machine has no pending ItemVended event.');
    }

    /**
     * Runs the real outbox relay once, publishing the pending item-vended
     * message to the integration exchange the Sales queue is bound to.
     */
    private function relay(): void
    {
        $serializer = new JsonEnvelopeSerializer([ItemVendedV1::TYPE => ItemVendedV1::class]);
        $bus = new MessengerIntegrationEventBus(AmqpTransportFactory::create($serializer));

        $tester = new CommandTester(
            new OutboxRelayCommand($this->documentManager, $bus, new VendingMachineIntegrationEventTranslator()),
        );

        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
    }

    /**
     * Runs the real Sales worker (through the hand-wired container) until it
     * has handled the given number of messages.
     */
    private function consumeSales(int $limit): void
    {
        $container = ContainerFactory::create();

        $command = $container->get(ConsumeMessagesCommand::class);
        self::assertInstanceOf(ConsumeMessagesCommand::class, $command);

        $tester = new CommandTester($command);
        $tester->execute([
            'receivers' => [MessengerCommandsFactory::SALES_TRANSPORT_NAME],
            '--limit' => (string) $limit,
            // Safety net: never hang if the expected message does not arrive.
            '--time-limit' => '30',
        ]);
    }

    private function publishToSales(ItemVendedV1Received $message): void
    {
        $this->salesTransport->send(new Envelope($message)->with(new AmqpStamp(routingKey: $message->type())));
    }

    /**
     * Runs a worker over the Sales transport whose bus always throws, with the
     * framework's retry and failure listeners wired the way the real worker
     * wires them — so the message is retried and then dead-lettered.
     */
    private function runFailingWorker(int $attempts): void
    {
        $retryStrategy = new MultiplierRetryStrategy(maxRetries: $attempts - 1, delayMilliseconds: 10);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(
            new SendFailedMessageForRetryListener(
                new ServiceLocator([
                    MessengerCommandsFactory::SALES_TRANSPORT_NAME => fn(): SenderInterface => $this->salesTransport,
                ]),
                new ServiceLocator([
                    MessengerCommandsFactory::SALES_TRANSPORT_NAME =>
                        static fn(): RetryStrategyInterface => $retryStrategy,
                ]),
            ),
        );
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator([
            MessengerCommandsFactory::SALES_TRANSPORT_NAME => fn(): SenderInterface => $this->failureTransport,
        ])));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($attempts));

        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new RuntimeException('The handler failed.');
            }
        };

        $worker = new Worker(
            [MessengerCommandsFactory::SALES_TRANSPORT_NAME => $this->salesTransport],
            $failingBus,
            $dispatcher,
        );
        $worker->run(['sleep' => 5000]);
    }

    /**
     * Drains every available message from a transport, acknowledging each, and
     * returns them. The AMQP receiver yields one message per call, so the drain
     * repeats until the queue is empty.
     *
     * @return list<Envelope>
     */
    private function drain(AmqpTransport $transport): array
    {
        $transport->setup();

        $envelopes = [];

        do {
            $batch = iterator_to_array($transport->get(), preserve_keys: false);

            foreach ($batch as $envelope) {
                $envelopes[] = $envelope;
                $transport->ack($envelope);
            }
        } while ($batch !== []);

        return $envelopes;
    }

    /**
     * @return list<array{_id: string, vendingMachineId: string, selectorCode: string, priceInCents: int, soldAt: string, sourceEventId: string}>
     */
    private function sales(): array
    {
        /** @var list<array{_id: string, vendingMachineId: string, selectorCode: string, priceInCents: int, soldAt: string, sourceEventId: string}> $sales */
        $sales = iterator_to_array(
            $this->documentManager->getDocumentCollection(SaleDocument::class)->find([], [
                'sort' => ['soldAt' => 1, '_id' => 1],
            ]),
            preserve_keys: false,
        );

        return $sales;
    }

    private function anItemVendedMessage(string $id): ItemVendedV1Received
    {
        return new ItemVendedV1Received(
            id: $id,
            vendingMachineId: '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
            aggregateVersion: 7,
            occurredAt: new DateTimeImmutable('2026-10-14T10:30:00+00:00'),
            correlationId: $id,
            selectorCode: 'WATER',
            priceInCents: 65,
            changeInCents: [25, 10],
        );
    }
}
