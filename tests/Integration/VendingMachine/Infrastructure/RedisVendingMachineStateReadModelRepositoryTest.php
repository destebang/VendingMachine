<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\RedisClientFactory;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

final class RedisVendingMachineStateReadModelRepositoryTest extends TestCase
{
    public function test_it_stores_and_retrieves_state(): void
    {
        $repository = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $id = bin2hex(random_bytes(8));

        self::assertNull($repository->get($id));

        $repository->put($id, ['stock' => 10]);

        self::assertSame(['stock' => 10], $repository->get($id));
    }
}
