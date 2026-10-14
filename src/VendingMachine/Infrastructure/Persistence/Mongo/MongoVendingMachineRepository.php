<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Persistence\Mongo;

use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * MongoDB-backed implementation of the write-side repository, via Doctrine
 * ODM. Translates between the persistence-agnostic `VendingMachine`
 * aggregate and the Mongo-specific `VendingMachineDocument`.
 *
 * Once a save has committed, the events the aggregate recorded are released
 * to the collector; the command-bus middleware publishes them when the
 * command is done, for every outcome.
 */
final class MongoVendingMachineRepository implements VendingMachineRepository
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly DomainEventCollector $eventCollector,
    ) {}

    public function find(VendingMachineId $id): ?VendingMachine
    {
        $document = $this->documentManager->find(VendingMachineDocument::class, $id->toString());

        if (!$document instanceof VendingMachineDocument) {
            return null;
        }

        return VendingMachine::reconstitute(
            $id,
            AcceptedDenominations::fromCents($document->acceptedDenominationsInCents),
            InsertedFunds::fromDenominations($document->insertedDenominationsInCents),
            Stock::fromProducts(self::stockFromDocument($document->stock)),
            CashReserve::fromCounts(self::cashReserveFromDocument($document->cashReserve)),
            $document->version,
        );
    }

    public function get(VendingMachineId $id): VendingMachine
    {
        return $this->find($id) ?? throw VendingMachineNotFoundException::withId($id);
    }

    public function save(VendingMachine $vendingMachine): void
    {
        $state = $vendingMachine->state();

        $document = $this->documentManager->find(VendingMachineDocument::class, $state->id->toString());

        if (!$document instanceof VendingMachineDocument) {
            $document = new VendingMachineDocument($state->id->toString(), $state->version);
            $this->documentManager->persist($document);
        }

        // The version field is ODM's optimistic-locking version and is never
        // assigned here. ODM bumps it by one on every flush that writes (and
        // seeds it from the aggregate on the initial insert), which stays in
        // step with the aggregate: it bumps once per mutation that records an
        // event, and every save flushes exactly one recorded mutation.
        $document->acceptedDenominationsInCents = $state->acceptedDenominations->denominationsInCents();
        $document->insertedDenominationsInCents = $state->insertedFunds->denominationsInCents();
        $document->stock = self::stockToDocument($state->stock->products());
        $document->cashReserve = self::cashReserveToDocument($state->cashReserve->counts());

        $this->documentManager->flush();

        // Only a committed write releases events: a flush that throws (e.g. an
        // optimistic-lock rejection) collects nothing, so a fact that never
        // persisted is never published.
        $this->eventCollector->collect(...$vendingMachine->releaseEvents());
    }

    /**
     * @param array<string, Product> $stock
     *
     * @return list<array{selectorCode: string, priceInCents: int, stock: int}>
     */
    private static function stockToDocument(array $stock): array
    {
        return array_values(array_map(static fn(Product $product): array => [
            'selectorCode' => $product->selectorCode(),
            'priceInCents' => $product->price()->amountInCents(),
            'stock' => $product->stock(),
        ], $stock));
    }

    /**
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     *
     * @return list<Product>
     */
    private static function stockFromDocument(array $stock): array
    {
        $products = [];
        foreach ($stock as $row) {
            $products[] = Product::create($row['selectorCode'], Money::fromCents($row['priceInCents']), $row['stock']);
        }

        return $products;
    }

    /**
     * @param array<int, int> $cashReserve
     *
     * @return list<array{denominationInCents: int, quantity: int}>
     */
    private static function cashReserveToDocument(array $cashReserve): array
    {
        $rows = [];
        foreach ($cashReserve as $denominationInCents => $quantity) {
            $rows[] = [
                'denominationInCents' => $denominationInCents,
                'quantity' => $quantity,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{denominationInCents: int, quantity: int}> $cashReserve
     *
     * @return array<int, int>
     */
    private static function cashReserveFromDocument(array $cashReserve): array
    {
        $counts = [];
        foreach ($cashReserve as $row) {
            $counts[$row['denominationInCents']] = $row['quantity'];
        }

        return $counts;
    }
}
