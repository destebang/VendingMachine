<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\ReadModel\Redis;

use App\VendingMachine\Domain\Read\MachineStateView;
use App\VendingMachine\Domain\Read\VendingMachineStateReadModelRepository;
use Predis\ClientInterface;

/**
 * Redis-backed implementation of the read model. Each machine's state is
 * stored as a single JSON document under `vending_machine:{id}`.
 *
 * This adapter owns the storage format: the port speaks `MachineStateView`, and
 * the JSON shape and key are translated here, so no caller sees a raw array.
 */
final class RedisVendingMachineStateReadModelRepository implements VendingMachineStateReadModelRepository
{
    public function __construct(
        private readonly ClientInterface $redis,
    ) {}

    public function put(MachineStateView $state): void
    {
        $this->redis->set($this->key($state->id), json_encode($state->toArray(), JSON_THROW_ON_ERROR));
    }

    public function get(string $vendingMachineId): ?MachineStateView
    {
        $raw = $this->redis->get($this->key($vendingMachineId));

        if ($raw === null) {
            return null;
        }

        /** @var array{id?: string, stock?: list<array{selectorCode: string, priceInCents: int, stock: int}>, cashReserve?: list<array{denominationInCents: int, quantity: int}>, insertedFundsInCents?: int} $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        return new MachineStateView(
            $decoded['id'] ?? '',
            $decoded['stock'] ?? [],
            $decoded['cashReserve'] ?? [],
            (int) ($decoded['insertedFundsInCents'] ?? 0),
        );
    }

    private function key(string $vendingMachineId): string
    {
        return sprintf('vending_machine:%s', $vendingMachineId);
    }
}
