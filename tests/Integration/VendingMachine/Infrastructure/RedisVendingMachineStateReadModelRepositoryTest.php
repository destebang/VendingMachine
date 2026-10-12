<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\RedisClientFactory;
use App\VendingMachine\Domain\Read\MachineStateView;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

final class RedisVendingMachineStateReadModelRepositoryTest extends TestCase
{
    public function test_it_stores_and_retrieves_state(): void
    {
        $repository = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $id = bin2hex(random_bytes(8));

        self::assertNull($repository->get($id));

        $repository->put(
            new MachineStateView(
                $id,
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 9]],
                [['denominationInCents' => 25, 'quantity' => 39]],
                0,
            ),
        );

        $state = $repository->get($id);

        self::assertNotNull($state);
        self::assertSame($id, $state->id);
        self::assertSame([['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 9]], $state->stock);
        self::assertSame([['denominationInCents' => 25, 'quantity' => 39]], $state->cashReserve);
        self::assertSame(0, $state->insertedFundsInCents);
    }
}
