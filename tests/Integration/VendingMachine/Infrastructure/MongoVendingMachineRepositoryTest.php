<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\DocumentManagerFactory;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
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
        self::assertTrue($found->state()->id->equals($id));
    }

    public function test_it_round_trips_stock_reserve_and_inserted_funds(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $repository->save(VendingMachine::reconstitute(
            $id,
            InsertedFunds::fromDenominations([25, 25]),
            Stock::fromProducts([
                Product::create('WATER', Money::fromCents(65), 4),
                Product::create('CHIPS', Money::fromCents(120), 7),
            ]),
            CashReserve::fromCounts([100 => 5, 50 => 3]),
            0,
        ));

        $found = $repository->find($id);

        self::assertNotNull($found);
        self::assertSame(50, $found->state()->insertedFunds->amount()->amountInCents());
        self::assertSame([25, 25], $found->state()->insertedFunds->denominationsInCents());
        self::assertSame([100 => 5, 50 => 3], $found->state()->cashReserve->counts());
        self::assertSame(4, $found->state()->stock->quantityOf('WATER'));
        self::assertSame(65, $found->state()->stock->products()['WATER']->price()->amountInCents());
        self::assertSame(7, $found->state()->stock->quantityOf('CHIPS'));
    }

    public function test_get_returns_the_stored_machine(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $repository->save(VendingMachine::create($id));

        $found = $repository->get($id);

        self::assertTrue($found->state()->id->equals($id));
    }

    public function test_get_fails_when_the_machine_does_not_exist(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());

        $this->expectException(VendingMachineNotFoundException::class);

        $repository->get(VendingMachineId::generate());
    }
}
