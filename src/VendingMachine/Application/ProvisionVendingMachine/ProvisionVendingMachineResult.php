<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ProvisionVendingMachine;

use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Result of provisioning a machine: the freshly generated identity clients use
 * to address it from now on.
 */
final class ProvisionVendingMachineResult
{
    public function __construct(
        private readonly VendingMachineId $vendingMachineId,
    ) {
    }

    public function id(): string
    {
        return $this->vendingMachineId->toString();
    }
}
