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
final readonly class VendingMachineState
{
    public function __construct(
        public VendingMachineId $id,
        public AcceptedDenominations $acceptedDenominations,
        public Stock $stock,
        public CashReserve $cashReserve,
        public InsertedFunds $insertedFunds,
        public int $version,
    ) {}
}
