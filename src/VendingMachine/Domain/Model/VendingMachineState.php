<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * Immutable snapshot of a vending machine: its identity and everything it
 * currently holds.
 *
 * This is the single outbound seam through which persistence and the read-model
 * projection read the aggregate, so the aggregate itself can expose behaviour
 * rather than a getter per field.
 */
final class VendingMachineState
{
    public function __construct(
        public readonly VendingMachineId $id,
        public readonly Stock $stock,
        public readonly CashReserve $cashReserve,
        public readonly InsertedFunds $insertedFunds,
        public readonly int $version,
    ) {
    }
}
