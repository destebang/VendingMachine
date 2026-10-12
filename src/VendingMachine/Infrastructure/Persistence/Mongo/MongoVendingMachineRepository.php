<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Persistence\Mongo;

use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
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
    ) {
    }

    public function find(VendingMachineId $id): ?VendingMachine
    {
        $document = $this->documentManager->find(VendingMachineDocument::class, $id->toString());

        if (!$document instanceof VendingMachineDocument) {
            return null;
        }

        return VendingMachine::reconstitute(
            $id,
            Money::fromCents($document->balanceInCents),
            $document->version,
            self::stockFromDocument($document->stock),
            self::changeFromDocument($document->change),
        );
    }

    public function save(VendingMachine $vendingMachine): void
    {
        $document = $this->documentManager->find(VendingMachineDocument::class, $vendingMachine->id()->toString());

        if (!$document instanceof VendingMachineDocument) {
            $document = new VendingMachineDocument($vendingMachine->id()->toString(), $vendingMachine->version());
            $this->documentManager->persist($document);
        }

        $document->balanceInCents = $vendingMachine->balance()->amountInCents();
        $document->stock = self::stockToDocument($vendingMachine->stock());
        $document->change = self::changeToDocument($vendingMachine->change());

        $this->documentManager->flush();
    }

    /**
     * @param array<string, Product> $stock
     *
     * @return list<array{selectorCode: string, priceInCents: int, stock: int}>
     */
    private static function stockToDocument(array $stock): array
    {
        return array_values(array_map(
            static fn (Product $product): array => [
                'selectorCode' => $product->selectorCode(),
                'priceInCents' => $product->price()->amountInCents(),
                'stock' => $product->stock(),
            ],
            $stock,
        ));
    }

    /**
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     *
     * @return array<string, Product>
     */
    private static function stockFromDocument(array $stock): array
    {
        $products = [];
        foreach ($stock as $row) {
            $products[$row['selectorCode']] = Product::create(
                $row['selectorCode'],
                Money::fromCents($row['priceInCents']),
                $row['stock'],
            );
        }

        return $products;
    }

    /**
     * @param array<int, int> $change
     *
     * @return list<array{denominationInCents: int, quantity: int}>
     */
    private static function changeToDocument(array $change): array
    {
        $rows = [];
        foreach ($change as $denominationInCents => $quantity) {
            $rows[] = [
                'denominationInCents' => $denominationInCents,
                'quantity' => $quantity,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{denominationInCents: int, quantity: int}> $change
     *
     * @return array<int, int>
     */
    private static function changeFromDocument(array $change): array
    {
        $reserves = [];
        foreach ($change as $row) {
            $reserves[$row['denominationInCents']] = $row['quantity'];
        }

        return $reserves;
    }
}
