<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\GetVendingMachineState;

/**
 * Read-side query: fetch the current projected state (stock, cash reserve,
 * inserted funds) of a vending machine from the Redis read model.
 */
final class GetVendingMachineState
{
    public function __construct(
        public readonly string $vendingMachineId,
    ) {
    }
}
