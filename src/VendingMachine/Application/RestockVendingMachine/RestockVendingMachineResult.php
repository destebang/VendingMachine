<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\RestockVendingMachine;

use App\VendingMachine\Application\ReadModel\MachineStateView;

/**
 * Result of a service action: the machine's state after the declared stock and
 * change have been applied.
 */
final class RestockVendingMachineResult
{
    public function __construct(
        public readonly MachineStateView $state,
    ) {
    }
}
