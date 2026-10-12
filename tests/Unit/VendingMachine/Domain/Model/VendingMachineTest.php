<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;

final class VendingMachineTest extends TestCase
{
    public function test_a_newly_created_machine_starts_empty(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        self::assertSame(0, $machine->version());
        self::assertTrue($machine->stock()->isEmpty());
        self::assertTrue($machine->cashReserve()->isEmpty());
        self::assertTrue($machine->insertedFunds()->isEmpty());
        self::assertFalse($machine->hasPendingTransaction());
    }

    public function test_incrementing_version_bumps_it_by_one(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        $machine->incrementVersion();
        $machine->incrementVersion();

        self::assertSame(2, $machine->version());
    }

    public function test_reconstitute_restores_identity_version_and_state(): void
    {
        $id = VendingMachineId::generate();
        $stock = Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 3)]);
        $cashReserve = CashReserve::fromCounts([100 => 2]);

        $machine = VendingMachine::reconstitute(
            $id,
            InsertedFunds::of(Money::fromCents(50)),
            $stock,
            $cashReserve,
            7,
        );

        self::assertTrue($machine->id()->equals($id));
        self::assertSame(7, $machine->version());
        self::assertSame(3, $machine->stock()->quantityOf('WATER'));
        self::assertSame(2, $machine->cashReserve()->quantityOf(100));
        self::assertSame(50, $machine->insertedFunds()->amount()->amountInCents());
    }

    public function test_service_replaces_rather_than_adds_to_stock_and_reserve(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
        );

        $machine->service(
            Stock::fromProducts([Product::create('CHIPS', Money::fromCents(120), 2)]),
            CashReserve::fromCounts([50 => 1]),
        );

        self::assertSame(['CHIPS'], array_keys($machine->stock()->products()));
        self::assertSame(2, $machine->stock()->quantityOf('CHIPS'));
        self::assertSame([50 => 1], $machine->cashReserve()->counts());
    }

    public function test_service_is_rejected_while_a_transaction_is_pending(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());
        $machine->insertFunds(Money::fromCents(50));

        $this->expectException(PendingTransactionInProgressException::class);

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
        );
    }

    public function test_service_leaves_state_untouched_when_rejected(): void
    {
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            InsertedFunds::of(Money::fromCents(50)),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 3)]),
            CashReserve::fromCounts([100 => 2]),
            0,
        );

        try {
            $machine->service(
                Stock::fromProducts([Product::create('CHIPS', Money::fromCents(120), 10)]),
                CashReserve::fromCounts([50 => 9]),
            );
            self::fail('Expected the service action to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException) {
            self::assertSame(['WATER'], array_keys($machine->stock()->products()));
            self::assertSame([100 => 2], $machine->cashReserve()->counts());
        }
    }

    public function test_inserting_funds_makes_the_transaction_pending(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        self::assertFalse($machine->hasPendingTransaction());

        $machine->insertFunds(Money::fromCents(50));
        $machine->insertFunds(Money::fromCents(25));

        self::assertTrue($machine->hasPendingTransaction());
        self::assertSame(75, $machine->insertedFunds()->amount()->amountInCents());
    }
}
