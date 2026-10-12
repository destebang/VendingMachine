<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\ReadModel\Redis;

use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use Predis\ClientInterface;

/**
 * Redis-backed implementation of the read model. Each machine's state is
 * stored as a single JSON document under `vending_machine:{id}`.
 */
final class RedisVendingMachineStateReadModelRepository implements VendingMachineStateReadModelRepository
{
    public function __construct(
        private readonly ClientInterface $redis,
    ) {
    }

    public function put(string $vendingMachineId, array $state): void
    {
        $this->redis->set($this->key($vendingMachineId), json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function get(string $vendingMachineId): ?array
    {
        $raw = $this->redis->get($this->key($vendingMachineId));

        if ($raw === null) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function key(string $vendingMachineId): string
    {
        return sprintf('vending_machine:%s', $vendingMachineId);
    }
}
