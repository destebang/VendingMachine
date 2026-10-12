<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\InsertCoin;

use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Customer action: insert a single coin into a machine so its value counts
 * toward the price of an item. Carries the target machine and the coin; whether
 * the machine actually accepts that denomination is a domain rule enforced when
 * the coin is inserted.
 */
final class InsertCoin
{
    public function __construct(
        public readonly VendingMachineId $vendingMachineId,
        public readonly Denomination $denomination,
    ) {}

    /**
     * Builds the command from already-decoded transport input (HTTP JSON body,
     * CLI option), so both driving adapters share the exact same translation
     * instead of duplicating it.
     */
    public static function fromInput(string $vendingMachineId, int $denominationInCents): self
    {
        return new self(
            VendingMachineId::fromString($vendingMachineId),
            Denomination::of(Money::fromCents($denominationInCents)),
        );
    }
}
