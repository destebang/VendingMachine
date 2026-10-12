<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\InsertCoin;

use App\VendingMachine\Domain\Model\VendingMachineState;
use App\VendingMachine\Domain\Read\MachineStateView;

/**
 * Result of inserting a coin: the machine's state after the coin was recorded,
 * whose `insertedFundsInCents` is the updated balance the customer now has.
 */
final class InsertCoinResult
{
    public function __construct(
        public readonly VendingMachineState $state,
    ) {}

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
        return MachineStateView::fromState($this->state)->toArray();
    }
}
