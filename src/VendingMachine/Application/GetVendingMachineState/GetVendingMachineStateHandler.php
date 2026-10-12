<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\GetVendingMachineState;

use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;

/**
 * Serves the current state of a machine straight from the read model, without
 * touching the write side. Returns null when the projection has no machine for
 * the id, which the driving adapter surfaces as "not found".
 */
final class GetVendingMachineStateHandler
{
    public function __construct(
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {
    }

    public function __invoke(GetVendingMachineState $query): ?MachineStateView
    {
        $state = $this->readModel->get($query->vendingMachineId);

        if ($state === null) {
            return null;
        }

        return MachineStateView::fromArray($state);
    }
}
