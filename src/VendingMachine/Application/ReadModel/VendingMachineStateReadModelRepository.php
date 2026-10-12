<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReadModel;

/**
 * Port for the Redis-backed read model. Kept separate from the write-side
 * `VendingMachineRepository` so each side of CQRS can evolve and scale
 * independently.
 *
 * This is bootstrap-stage scaffolding: it stores and retrieves an opaque
 * state array per machine id with no mapping logic yet. Once real use cases
 * land, this will carry a proper `MachineStateView` DTO instead of a raw
 * array.
 */
interface VendingMachineStateReadModelRepository
{
    /**
     * @param array<string, mixed> $state
     */
    public function put(string $vendingMachineId, array $state): void;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $vendingMachineId): ?array;
}
