<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\SelectItem;

use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Customer action: select the product with the given selector code, paying for
 * it out of the funds already inserted and receiving any change the machine owes
 * back. Carries the target machine and the selector code; whether the machine can
 * actually serve that selection is a domain rule enforced when it is selected.
 */
final class SelectItem
{
    public function __construct(
        public readonly VendingMachineId $vendingMachineId,
        public readonly string $selectorCode,
    ) {
    }

    /**
     * Builds the command from already-decoded transport input (HTTP JSON body,
     * CLI argument), so both driving adapters share the exact same translation
     * instead of duplicating it.
     */
    public static function fromInput(string $vendingMachineId, string $selectorCode): self
    {
        return new self(VendingMachineId::fromString($vendingMachineId), $selectorCode);
    }
}
