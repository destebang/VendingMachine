<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

/**
 * A technician declared the machine's true stock and cash reserve, replacing
 * whatever was there. Rejected while a transaction is pending, so a serviced
 * event never overlaps inserted funds.
 */
final readonly class VendingMachineServiced extends VendingMachineEvent
{
    /**
     * @return array{state: array{
     *     id: string,
     *     acceptedDenominationsInCents: list<int>,
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
