<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReturnCoin;

use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Customer action: take back every coin inserted so far, ending the pending
 * transaction without a sale. Carries only the target machine; the coins handed
 * back are exactly those the customer inserted.
 */
final class ReturnCoin
{
    public function __construct(
        public readonly VendingMachineId $vendingMachineId,
    ) {}

    /**
     * Builds the command from already-decoded transport input (HTTP path
     * parameter, CLI argument), so both driving adapters share the exact same
     * translation instead of duplicating it.
     */
    public static function fromInput(string $vendingMachineId): self
    {
        return new self(VendingMachineId::fromString($vendingMachineId));
    }
}
