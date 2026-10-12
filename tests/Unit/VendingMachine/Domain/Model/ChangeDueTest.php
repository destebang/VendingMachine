<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Model\ChangeDue;
use PHPUnit\Framework\TestCase;

final class ChangeDueTest extends TestCase
{
    public function test_none_holds_nothing(): void
    {
        $change = ChangeDue::none();

        self::assertTrue($change->isEmpty());
        self::assertSame([], $change->denominationsInCents());
        self::assertSame(0, $change->total()->amountInCents());
    }

    public function test_it_expands_denominations_largest_first(): void
    {
        $change = ChangeDue::fromCounts([10 => 1, 25 => 1, 5 => 2]);

        self::assertFalse($change->isEmpty());
        self::assertSame([25, 10, 5, 5], $change->denominationsInCents());
        self::assertSame(45, $change->total()->amountInCents());
    }

    public function test_zero_quantity_lines_are_dropped(): void
    {
        $change = ChangeDue::fromCounts([25 => 0, 10 => 2]);

        self::assertSame([10, 10], $change->denominationsInCents());
        self::assertSame(20, $change->total()->amountInCents());
    }

    public function test_equal_change_compares_by_value_regardless_of_order(): void
    {
        self::assertTrue(ChangeDue::fromCounts([25 => 1, 10 => 1])->equals(ChangeDue::fromCounts([10 => 1, 25 => 1])));
        self::assertFalse(ChangeDue::fromCounts([25 => 1])->equals(ChangeDue::none()));
    }
}
