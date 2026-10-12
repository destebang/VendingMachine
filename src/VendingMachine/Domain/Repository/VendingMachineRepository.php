<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Repository;

use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Port for persisting and loading vending machine aggregates. The domain and
 * application layers depend only on this interface; `Infrastructure` is
 * free to swap the concrete storage technology without either layer
 * noticing.
 */
interface VendingMachineRepository
{
    /**
     * The machine for the id, or null when the write model has none. Use when
     * absence is a valid outcome the caller handles itself.
     */
    public function find(VendingMachineId $id): ?VendingMachine;

    /**
     * The machine for the id, or a `VendingMachineNotFoundException` when the
     * write model has none. Use when the machine is expected to exist, so the
     * caller does not repeat the same null check.
     */
    public function get(VendingMachineId $id): VendingMachine;

    public function save(VendingMachine $vendingMachine): void;
}
