<?php

declare(strict_types=1);

namespace App\Sales\Application\RegisterSale;

use DateTimeImmutable;

/**
 * Registers a sale that a vending machine reported through
 * `vending_machine.item_vended.v1`.
 *
 * The command is the Sales context's own shape: primitives only, with no
 * reference to the vending machine's model or to its message class. The
 * subscriber translates the received message into this command, and the
 * handler mints the sale's identity.
 */
final class RegisterSale
{
    public function __construct(
        public readonly string $vendingMachineId,
        public readonly string $selectorCode,
        public readonly int $priceInCents,
        public readonly DateTimeImmutable $soldAt,
        public readonly string $sourceEventId,
    ) {}
}
