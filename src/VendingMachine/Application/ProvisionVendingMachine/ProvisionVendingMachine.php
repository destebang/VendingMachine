<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ProvisionVendingMachine;

use App\VendingMachine\Domain\Model\AcceptedDenominations;

/**
 * Provisions a brand-new vending machine: a fresh identity configured with the
 * denominations it accepts, empty stock, empty cash reserve and no inserted
 * funds. The accepted set is required — a machine must take at least one
 * denomination.
 */
final class ProvisionVendingMachine
{
    public function __construct(
        public readonly AcceptedDenominations $acceptedDenominations,
    ) {}

    /**
     * Builds the command from already-decoded transport input (HTTP JSON body,
     * CLI option), so both driving adapters share the exact same translation
     * instead of duplicating it.
     *
     * @param list<int> $acceptedDenominationsInCents
     */
    public static function fromInput(array $acceptedDenominationsInCents): self
    {
        return new self(AcceptedDenominations::fromCents($acceptedDenominationsInCents));
    }
}
