<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Read;

/**
 * Port for the Redis-backed read model. Kept separate from the write-side
 * `VendingMachineRepository` so each side of CQRS can evolve and scale
 * independently.
 *
 * Typed to the read-side `MachineStateView`: the adapter owns serialisation and
 * key format, so no caller sees a raw array.
 */
interface VendingMachineStateReadModelRepository
{
    public function put(MachineStateView $state): void;

    public function get(string $vendingMachineId): ?MachineStateView;
}
