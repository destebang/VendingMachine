<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidCashReserveException;
use App\VendingMachine\Domain\Model\CashReserve;
use PHPUnit\Framework\TestCase;

final class CashReserveTest extends TestCase
{
    public function test_empty_reserve_holds_nothing(): void
    {
        $reserve = CashReserve::empty();

        self::assertTrue($reserve->isEmpty());
        self::assertSame(0, $reserve->quantityOf(100));
        self::assertSame(0, $reserve->total()->amountInCents());
        self::assertSame([], $reserve->counts());
    }

    public function test_it_holds_counts_by_denomination_and_totals_them(): void
    {
        $reserve = CashReserve::fromCounts([100 => 5, 50 => 2]);

        self::assertFalse($reserve->isEmpty());
        self::assertSame(5, $reserve->quantityOf(100));
        self::assertSame(2, $reserve->quantityOf(50));
        self::assertSame(600, $reserve->total()->amountInCents());
        self::assertSame([100 => 5, 50 => 2], $reserve->counts());
    }

    public function test_it_rejects_a_non_positive_denomination(): void
    {
        $this->expectException(InvalidCashReserveException::class);

        CashReserve::fromCounts([0 => 1]);
    }

    public function test_it_rejects_a_negative_quantity(): void
    {
        $this->expectException(InvalidCashReserveException::class);

        CashReserve::fromCounts([100 => -1]);
    }
}
