<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Persistence\Mongo;

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
 */
final class MongoVendingMachineRepository implements VendingMachineRepository
{
    public function __construct(
        private readonly DocumentManager $documentManager,
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

        $document->acceptedDenominationsInCents = $state->acceptedDenominations->denominationsInCents();
        $document->insertedDenominationsInCents = $state->insertedFunds->denominationsInCents();
        $document->stock = self::stockToDocument($state->stock->products());
        $document->cashReserve = self::cashReserveToDocument($state->cashReserve->counts());

        $this->documentManager->flush();
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
