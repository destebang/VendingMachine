<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\HealthCheck;

final class HealthCheckResult
{
    public function __construct(
        public readonly bool $mongoOk,
        public readonly bool $redisOk,
    ) {}

    public function isHealthy(): bool
    {
        return $this->mongoOk && $this->redisOk;
    }

    /**
     * @return array{mongo: bool, redis: bool, healthy: bool}
     */
    public function toArray(): array
    {
        return [
            'mongo' => $this->mongoOk,
            'redis' => $this->redisOk,
            'healthy' => $this->isHealthy(),
        ];
    }
}
