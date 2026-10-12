<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\DocumentManagerFactory;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use PHPUnit\Framework\TestCase;

final class MongoVendingMachineRepositoryTest extends TestCase
{
    public function test_it_persists_and_retrieves_a_vending_machine(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        self::assertNull($repository->find($id));

        $repository->save(VendingMachine::create($id));

        $found = $repository->find($id);

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($id));
    }

    public function test_it_round_trips_stock_change_and_balance(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $repository->save(VendingMachine::reconstitute(
            $id,
            Money::fromCents(50),
            0,
            [
                'WATER' => Product::create('WATER', Money::fromCents(65), stock: 4),
                'CHIPS' => Product::create('CHIPS', Money::fromCents(120), stock: 7),
            ],
            [100 => 5, 50 => 3],
        ));

        $found = $repository->find($id);

        self::assertNotNull($found);
        self::assertSame(50, $found->balance()->amountInCents());
        self::assertSame([100 => 5, 50 => 3], $found->change());
        self::assertSame(4, $found->stock()['WATER']->stock());
        self::assertSame(65, $found->stock()['WATER']->price()->amountInCents());
        self::assertSame(7, $found->stock()['CHIPS']->stock());
    }
}
