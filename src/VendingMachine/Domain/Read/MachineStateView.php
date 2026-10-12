<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Read;

use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * Read-side projection of a single vending machine: the flat, serialisable
 * shape stored in the Redis read model and served by the query side.
 *
 * Distinct from the write-side `VendingMachineState` it is built from — the
 * write snapshot is the events' contract, this is the read side's. The Redis
 * adapter owns its storage format; no caller of the read-model port sees a raw
 * array.
 */
final readonly class MachineStateView
{
    /**
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $cashReserve
     */
    public function __construct(
        public string $id,
        public array $stock,
        public array $cashReserve,
        public int $insertedFundsInCents,
    ) {}

    /**
     * Builds the read shape from the write-side snapshot an event carries.
     */
    public static function fromState(VendingMachineState $state): self
    {
        $stock = [];
        foreach ($state->stock->products() as $product) {
            $stock[] = [
                'selectorCode' => $product->selectorCode(),
                'priceInCents' => $product->price()->amountInCents(),
                'stock' => $product->stock(),
            ];
        }

        $cashReserve = [];
        foreach ($state->cashReserve->counts() as $denominationInCents => $quantity) {
            $cashReserve[] = [
                'denominationInCents' => $denominationInCents,
                'quantity' => $quantity,
            ];
        }

        return new self(
            id: $state->id->toString(),
            stock: $stock,
            cashReserve: $cashReserve,
            insertedFundsInCents: $state->insertedFunds->amount()->amountInCents(),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     insertedFundsInCents: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'stock' => $this->stock,
            'cashReserve' => $this->cashReserve,
            'insertedFundsInCents' => $this->insertedFundsInCents,
        ];
    }
}
