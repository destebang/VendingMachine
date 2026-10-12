<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\InsertCoin;

use App\VendingMachine\Application\ReadModel\MachineStateView;

/**
 * Result of inserting a coin: the machine's state after the coin was recorded,
 * whose `insertedFundsInCents` is the updated balance the customer now has.
 */
final class InsertCoinResult
{
    public function __construct(
        public readonly MachineStateView $state,
    ) {}
}
