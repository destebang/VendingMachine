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
        $state = VendingMachine::create(VendingMachineId::generate())->state();

        self::assertSame(0, $state->version);
        self::assertTrue($state->stock->isEmpty());
        self::assertTrue($state->cashReserve->isEmpty());
        self::assertTrue($state->insertedFunds->isEmpty());
    }

    public function test_reconstitute_restores_identity_version_and_state(): void
    {
        $id = VendingMachineId::generate();

        $machine = VendingMachine::reconstitute(
            $id,
            InsertedFunds::of(Money::fromCents(50)),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 3)]),
            CashReserve::fromCounts([100 => 2]),
            7,
        );

        $state = $machine->state();

        self::assertTrue($state->id->equals($id));
        self::assertSame(7, $state->version);
        self::assertSame(3, $state->stock->quantityOf('WATER'));
        self::assertSame(2, $state->cashReserve->quantityOf(100));
        self::assertSame(50, $state->insertedFunds->amount()->amountInCents());
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

        $state = $machine->state();

        self::assertSame(['CHIPS'], array_keys($state->stock->products()));
        self::assertSame(2, $state->stock->quantityOf('CHIPS'));
        self::assertSame([50 => 1], $state->cashReserve->counts());
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
            $state = $machine->state();

            self::assertSame(['WATER'], array_keys($state->stock->products()));
            self::assertSame([100 => 2], $state->cashReserve->counts());
        }
    }

    public function test_inserting_funds_is_recorded(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        $machine->insertFunds(Money::fromCents(50));
        $machine->insertFunds(Money::fromCents(25));

        self::assertSame(75, $machine->state()->insertedFunds->amount()->amountInCents());
    }
}
