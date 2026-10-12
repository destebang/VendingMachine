<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ServiceVendingMachine;

use App\VendingMachine\Application\ReadModel\MachineStateView;

/**
 * Result of a service action: the machine's state after the declared stock and
 * cash reserve have been applied.
 */
final class ServiceVendingMachineResult
{
    public function __construct(
        public readonly MachineStateView $state,
    ) {}
}
