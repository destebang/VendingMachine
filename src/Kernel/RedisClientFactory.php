<?php

declare(strict_types=1);

namespace App\Kernel;

use Predis\Client;
use Predis\ClientInterface;

/**
 * Builds the Predis client by hand from the `REDIS_URL` environment
 * variable (see docker-compose.yml).
 */
final class RedisClientFactory
{
    public static function create(): ClientInterface
    {
        return new Client($_ENV['REDIS_URL'] ?? 'redis://redis:6379/0');
    }
}
