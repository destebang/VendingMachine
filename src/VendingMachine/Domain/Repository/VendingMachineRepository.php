<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Repository;

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
    public function find(VendingMachineId $id): ?VendingMachine;

    public function save(VendingMachine $vendingMachine): void;
}
