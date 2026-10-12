<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReadModel;

use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\VendingMachine;

/**
 * Read-side projection of a single vending machine: the flat, serialisable
 * shape stored in the Redis read model and served by the query side.
 *
 * It is deliberately decoupled from the write-side aggregate, but built from
 * it (and rebuilt from stored state) in exactly one place so the projection
 * shape never leaks into the handlers.
 */
final class MachineStateView
{
    /**
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $change
     */
    public function __construct(
        public readonly string $id,
        public readonly array $stock,
        public readonly array $change,
        public readonly int $balanceInCents,
    ) {
    }

    public static function fromAggregate(VendingMachine $vendingMachine): self
    {
        $stock = array_map(
            static fn (Product $product): array => [
                'selectorCode' => $product->selectorCode(),
                'priceInCents' => $product->price()->amountInCents(),
                'stock' => $product->stock(),
            ],
            array_values($vendingMachine->stock()),
        );

        $change = [];
        foreach ($vendingMachine->change() as $denominationInCents => $quantity) {
            $change[] = [
                'denominationInCents' => $denominationInCents,
                'quantity' => $quantity,
            ];
        }

        return new self(
            id: $vendingMachine->id()->toString(),
            stock: array_values($stock),
            change: $change,
            balanceInCents: $vendingMachine->balance()->amountInCents(),
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromArray(array $state): self
    {
        /** @var list<array{selectorCode: string, priceInCents: int, stock: int}> $stock */
        $stock = $state['stock'] ?? [];

        /** @var list<array{denominationInCents: int, quantity: int}> $change */
        $change = $state['change'] ?? [];

        return new self(
            id: (string) ($state['id'] ?? ''),
            stock: $stock,
            change: $change,
            balanceInCents: (int) ($state['balanceInCents'] ?? 0),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     change: list<array{denominationInCents: int, quantity: int}>,
     *     balanceInCents: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'stock' => $this->stock,
            'change' => $this->change,
            'balanceInCents' => $this->balanceInCents,
        ];
    }
}
