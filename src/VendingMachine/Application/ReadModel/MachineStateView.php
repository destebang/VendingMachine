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
     * @param list<int>                                                        $acceptedDenominationsInCents
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $cashReserve
     */
    public function __construct(
        public readonly string $id,
        public readonly array $acceptedDenominationsInCents,
        public readonly array $stock,
        public readonly array $cashReserve,
        public readonly int $insertedFundsInCents,
    ) {}

    public static function fromAggregate(VendingMachine $vendingMachine): self
    {
        $state = $vendingMachine->state();

        $stock = array_map(static fn(Product $product): array => [
            'selectorCode' => $product->selectorCode(),
            'priceInCents' => $product->price()->amountInCents(),
            'stock' => $product->stock(),
        ], array_values($state->stock->products()));

        $cashReserve = [];
        foreach ($state->cashReserve->counts() as $denominationInCents => $quantity) {
            $cashReserve[] = [
                'denominationInCents' => $denominationInCents,
                'quantity' => $quantity,
            ];
        }

        return new self(
            id: $state->id->toString(),
            acceptedDenominationsInCents: $state->acceptedDenominations->denominationsInCents(),
            stock: array_values($stock),
            cashReserve: $cashReserve,
            insertedFundsInCents: $state->insertedFunds->amount()->amountInCents(),
        );
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function fromArray(array $state): self
    {
        /** @var list<int> $acceptedDenominationsInCents */
        $acceptedDenominationsInCents = $state['acceptedDenominationsInCents'] ?? [];

        /** @var list<array{selectorCode: string, priceInCents: int, stock: int}> $stock */
        $stock = $state['stock'] ?? [];

        /** @var list<array{denominationInCents: int, quantity: int}> $cashReserve */
        $cashReserve = $state['cashReserve'] ?? [];

        return new self(
            id: (string) ($state['id'] ?? ''),
            acceptedDenominationsInCents: $acceptedDenominationsInCents,
            stock: $stock,
            cashReserve: $cashReserve,
            insertedFundsInCents: (int) ($state['insertedFundsInCents'] ?? 0),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     acceptedDenominationsInCents: list<int>,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     insertedFundsInCents: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'acceptedDenominationsInCents' => $this->acceptedDenominationsInCents,
            'stock' => $this->stock,
            'cashReserve' => $this->cashReserve,
            'insertedFundsInCents' => $this->insertedFundsInCents,
        ];
    }
}
