<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\EventBus;

use App\Kernel\ContainerFactory;
use App\Kernel\MongoCollectionFactory;
use App\Tests\Integration\IntegrationTestCase;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Dispatches the write commands through the real command bus (release
 * middleware, event bus and MongoDB store included) and asserts the traceability
 * documents appended to the `domain_events` collection.
 */
final class DomainEventPublicationTest extends IntegrationTestCase
{
    private ContainerBuilder $container;

    private Collection $domainEvents;

    protected function setUp(): void
    {
        $this->container = ContainerFactory::create();
        $this->domainEvents = MongoCollectionFactory::domainEvents();
        $this->domainEvents->deleteMany([]);
    }

    public function test_provisioning_persists_a_provisioned_event(): void
    {
        $id = $this->provision();

        $documents = $this->documentsOfType('VendingMachineProvisioned');

        self::assertCount(1, $documents);
        $document = $documents[0];
        self::assertSame('VendingMachine', $document['aggregateType']);
        self::assertSame($id, $document['aggregateId']);
        self::assertInstanceOf(UTCDateTime::class, $document['occurredAt']);
        self::assertSame(
            [
                'state' => [
                    'id' => $id,
                    'stock' => [],
                    'cashReserve' => [],
                    'insertedFunds' => ['denominationsInCents' => []],
                    'version' => 0,
                ],
            ],
            $document['payload'],
        );
    }

    public function test_servicing_persists_a_serviced_event(): void
    {
        $id = $this->provision();

        $this->service($id);

        $documents = $this->documentsOfType('VendingMachineServiced');

        self::assertCount(1, $documents);
        $state = self::stateOf($documents[0]);
        self::assertSame([['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]], $state['stock']);
        self::assertSame(
            [
                ['denominationInCents' => 25, 'quantity' => 4],
                ['denominationInCents' => 10, 'quantity' => 4],
            ],
            $state['cashReserve'],
        );
    }

    public function test_inserting_a_coin_persists_a_coin_inserted_event(): void
    {
        $id = $this->provision();

        $this->insertCoin($id, 25);

        $documents = $this->documentsOfType('CoinInserted');

        self::assertCount(1, $documents);
        $payload = self::payloadOf($documents[0]);
        self::assertSame(['amountInCents' => 25], $payload['denomination']);
        self::assertSame(['denominationsInCents' => [25]], self::asArray($payload['state'])['insertedFunds']);
    }

    public function test_a_successful_selection_persists_an_item_vended_event(): void
    {
        $id = $this->provision();
        $this->service($id);
        $this->insertCoin($id, 100);

        $this->dispatch($this->container, SelectItem::fromInput($id, 'WATER'));

        $documents = $this->documentsOfType('ItemVended');

        self::assertCount(1, $documents);
        $payload = self::payloadOf($documents[0]);
        self::assertSame(
            ['selectorCode' => 'WATER', 'priceInCents' => 65, 'change' => [25, 10]],
            $payload['vendedItem'],
        );
        $state = self::asArray($payload['state']);
        self::assertSame([['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 9]], $state['stock']);
        self::assertSame(['denominationsInCents' => []], $state['insertedFunds']);
    }

    public function test_returning_funds_persists_an_inserted_funds_returned_event(): void
    {
        $id = $this->provision();
        $this->insertCoin($id, 25);
        $this->insertCoin($id, 10);

        $this->dispatch($this->container, ReturnCoin::fromInput($id));

        $documents = $this->documentsOfType('InsertedFundsReturned');

        self::assertCount(1, $documents);
        $payload = self::payloadOf($documents[0]);
        self::assertSame(['denominationsInCents' => [25, 10]], $payload['returnedFunds']);
        self::assertSame(['denominationsInCents' => []], self::asArray($payload['state'])['insertedFunds']);
    }

    public function test_an_exact_change_rejection_persists_the_refund_before_it_throws(): void
    {
        $id = $this->provision();
        $this->service($id, stock: [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]], cashReserve: []);

        foreach ([25, 25, 25, 10, 10, 5] as $denominationInCents) {
            $this->insertCoin($id, $denominationInCents);
        }

        $thrown = $this->dispatchExpectingFailure($this->container, SelectItem::fromInput($id, 'WATER'));

        self::assertInstanceOf(InsufficientChangeAvailableException::class, $thrown);

        $documents = $this->documentsOfType('InsertedFundsReturnedDueToInsufficientChange');

        self::assertCount(1, $documents);
        $payload = self::payloadOf($documents[0]);
        self::assertSame(['denominationsInCents' => [25, 25, 25, 10, 10, 5]], $payload['returnedFunds']);
        self::assertSame(['denominationsInCents' => []], self::asArray($payload['state'])['insertedFunds']);
    }

    public function test_a_rejected_denomination_publishes_nothing(): void
    {
        $id = $this->provision();

        $thrown = $this->dispatchExpectingFailure($this->container, InsertCoin::fromInput($id, 50));

        self::assertInstanceOf(InvalidDenominationException::class, $thrown);
        self::assertCount(0, $this->documentsOfType('CoinInserted'));
        self::assertCount(1, $this->documentsOfType('VendingMachineProvisioned'));
    }

    public function test_an_out_of_stock_selection_publishes_nothing(): void
    {
        $id = $this->provision();
        $this->service($id, stock: [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 0]]);
        $this->insertCoin($id, 100);

        $thrown = $this->dispatchExpectingFailure($this->container, SelectItem::fromInput($id, 'WATER'));

        self::assertInstanceOf(OutOfStockException::class, $thrown);
        self::assertCount(0, $this->documentsOfType('ItemVended'));
    }

    public function test_an_insufficient_funds_selection_publishes_nothing(): void
    {
        $id = $this->provision();
        $this->service($id);
        $this->insertCoin($id, 25);

        $thrown = $this->dispatchExpectingFailure($this->container, SelectItem::fromInput($id, 'WATER'));

        self::assertInstanceOf(InsufficientFundsException::class, $thrown);
        self::assertCount(0, $this->documentsOfType('ItemVended'));
    }

    private function provision(): string
    {
        $this->dispatch($this->container, new ProvisionVendingMachine());

        $documents = $this->documentsOfType('VendingMachineProvisioned');
        self::assertCount(1, $documents);

        return (string) $documents[0]['aggregateId'];
    }

    /**
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $cashReserve
     */
    private function service(
        string $id,
        array $stock = [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
        array $cashReserve = [
            ['denominationInCents' => 25, 'quantity' => 4],
            ['denominationInCents' => 10, 'quantity' => 4],
        ],
    ): void {
        $this->dispatch($this->container, ServiceVendingMachine::fromInput($id, $stock, $cashReserve));
    }

    private function insertCoin(string $id, int $denominationInCents): void
    {
        $this->dispatch($this->container, InsertCoin::fromInput($id, $denominationInCents));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentsOfType(string $eventType): array
    {
        $typeMap = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

        $documents = [];
        foreach ($this->domainEvents->find(['eventType' => $eventType], ['typeMap' => $typeMap]) as $document) {
            /** @var array<string, mixed> $document */
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function payloadOf(array $document): array
    {
        return self::asArray($document['payload']);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function stateOf(array $document): array
    {
        return self::asArray(self::payloadOf($document)['state']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
