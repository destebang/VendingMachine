<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;

final class VendingMachineTest extends TestCase
{
    public function test_a_newly_created_machine_starts_empty(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        self::assertSame(0, $machine->version());
        self::assertSame([], $machine->stock());
        self::assertSame([], $machine->change());
        self::assertTrue($machine->balance()->isZero());
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
        $stock = ['WATER' => Product::create('WATER', Money::fromCents(65), stock: 3)];

        $machine = VendingMachine::reconstitute($id, Money::fromCents(50), 7, $stock, [100 => 2]);

        self::assertTrue($machine->id()->equals($id));
        self::assertSame(7, $machine->version());
        self::assertSame($stock, $machine->stock());
        self::assertSame([100 => 2], $machine->change());
        self::assertSame(50, $machine->balance()->amountInCents());
    }

    public function test_restock_replaces_rather_than_adds_to_stock_and_change(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        $machine->restock(
            ['WATER' => Product::create('WATER', Money::fromCents(65), stock: 10)],
            [100 => 5],
        );

        $machine->restock(
            ['CHIPS' => Product::create('CHIPS', Money::fromCents(120), stock: 2)],
            [50 => 1],
        );

        self::assertSame(['CHIPS'], array_keys($machine->stock()));
        self::assertSame(2, $machine->stock()['CHIPS']->stock());
        self::assertSame([50 => 1], $machine->change());
    }

    public function test_restock_is_rejected_while_a_transaction_is_pending(): void
    {
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            Money::fromCents(50),
            0,
            [],
            [],
        );

        $this->expectException(PendingTransactionInProgressException::class);

        $machine->restock(
            ['WATER' => Product::create('WATER', Money::fromCents(65), stock: 10)],
            [100 => 5],
        );
    }

    public function test_restock_leaves_state_untouched_when_rejected(): void
    {
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            Money::fromCents(50),
            0,
            ['WATER' => Product::create('WATER', Money::fromCents(65), stock: 3)],
            [100 => 2],
        );

        try {
            $machine->restock(
                ['CHIPS' => Product::create('CHIPS', Money::fromCents(120), stock: 10)],
                [50 => 9],
            );
            self::fail('Expected the restock to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException) {
            self::assertSame(['WATER'], array_keys($machine->stock()));
            self::assertSame([100 => 2], $machine->change());
        }
    }

    public function test_has_pending_transaction_reflects_the_inserted_balance(): void
    {
        self::assertFalse(
            VendingMachine::reconstitute(VendingMachineId::generate(), Money::zero(), 0, [], [])->hasPendingTransaction(),
        );
        self::assertTrue(
            VendingMachine::reconstitute(VendingMachineId::generate(), Money::fromCents(1), 0, [], [])->hasPendingTransaction(),
        );
    }
}
