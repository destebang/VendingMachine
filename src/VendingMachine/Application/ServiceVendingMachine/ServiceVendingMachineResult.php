<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ServiceVendingMachine;

use App\VendingMachine\Domain\Model\VendingMachineState;
use App\VendingMachine\Domain\Read\MachineStateView;

/**
 * Result of a service action: the machine's state after the declared stock and
 * cash reserve have been applied.
 */
final class ServiceVendingMachineResult
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
