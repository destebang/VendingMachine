<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

/**
 * A brand-new machine was provisioned: empty stock, empty cash reserve, no
 * inserted funds. The first event in a machine's life.
 */
final readonly class VendingMachineProvisioned extends VendingMachineEvent
{
    /**
     * @return array{state: array{
     *     id: string,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     insertedFunds: array{denominationsInCents: list<int>},
     *     version: int
     * }}
     */
    public function payload(): array
    {
        return [
            'state' => $this->stateSnapshot(),
        ];
    }
}
