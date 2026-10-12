<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InvalidCashReserveException;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\ChangeDue;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use PHPUnit\Framework\TestCase;

final class CashReserveTest extends TestCase
{
    public function test_empty_reserve_holds_nothing(): void
    {
        $reserve = CashReserve::empty();

        self::assertTrue($reserve->isEmpty());
        self::assertSame(0, $reserve->quantityOf(Denomination::of(Money::fromCents(100))));
        self::assertSame(0, $reserve->total()->amountInCents());
        self::assertSame([], $reserve->counts());
    }

    public function test_it_holds_counts_by_denomination_and_totals_them(): void
    {
        $reserve = CashReserve::fromCounts([100 => 5, 50 => 2]);

        self::assertFalse($reserve->isEmpty());
        self::assertSame(5, $reserve->quantityOf(Denomination::of(Money::fromCents(100))));
        self::assertSame(2, $reserve->quantityOf(Denomination::of(Money::fromCents(50))));
        self::assertSame(600, $reserve->total()->amountInCents());
        self::assertSame([100 => 5, 50 => 2], $reserve->counts());
    }

    public function test_a_denomination_it_does_not_hold_counts_as_zero(): void
    {
        $reserve = CashReserve::fromCounts([100 => 5]);

        self::assertSame(0, $reserve->quantityOf(Denomination::of(Money::fromCents(50))));
    }

    public function test_zero_quantity_lines_are_dropped(): void
    {
        $reserve = CashReserve::fromCounts([100 => 0, 50 => 2]);

        self::assertFalse($reserve->isEmpty());
        self::assertSame(0, $reserve->quantityOf(Denomination::of(Money::fromCents(100))));
        self::assertSame([50 => 2], $reserve->counts());
    }

    public function test_a_reserve_of_only_zero_quantity_lines_is_empty(): void
    {
        self::assertTrue(CashReserve::fromCounts([100 => 0])->isEmpty());
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

    public function test_it_makes_change_preferring_the_largest_denominations(): void
    {
        $reserve = CashReserve::fromCounts([25 => 40, 10 => 40, 5 => 40]);

        $change = $reserve->withdraw(Money::fromCents(35));

        self::assertSame([25, 10], $change->denominationsInCents());
        self::assertSame(35, $change->total()->amountInCents());
    }

    public function test_it_backtracks_when_the_largest_denomination_cannot_complete_the_amount(): void
    {
        // Greedy would take the 25 and then be stuck without a 5; the only way to
        // make 30 is three 10s.
        $reserve = CashReserve::fromCounts([25 => 1, 10 => 3]);

        $change = $reserve->withdraw(Money::fromCents(30));

        self::assertSame([10, 10, 10], $change->denominationsInCents());
        self::assertSame(30, $change->total()->amountInCents());
    }

    public function test_withdrawing_nothing_returns_no_change(): void
    {
        $change = CashReserve::fromCounts([25 => 1])->withdraw(Money::zero());

        self::assertTrue($change->isEmpty());
        self::assertSame(0, $change->total()->amountInCents());
    }

    public function test_it_reports_whether_it_can_make_an_amount(): void
    {
        $reserve = CashReserve::fromCounts([25 => 1]);

        self::assertTrue($reserve->canMake(Money::fromCents(25)));
        self::assertTrue($reserve->canMake(Money::zero()));
        self::assertFalse($reserve->canMake(Money::fromCents(30)));
    }

    public function test_it_cannot_withdraw_an_amount_the_held_coins_cannot_sum_to(): void
    {
        $this->expectException(InsufficientChangeAvailableException::class);

        CashReserve::fromCounts([25 => 1])->withdraw(Money::fromCents(30));
    }

    public function test_reduced_by_removes_the_withdrawn_coins(): void
    {
        $reserve = CashReserve::fromCounts([25 => 40, 10 => 40, 5 => 40]);

        $remaining = $reserve->reducedBy(ChangeDue::fromCounts([25 => 1, 10 => 1]));

        self::assertSame([25 => 39, 10 => 39, 5 => 40], $remaining->counts());
    }

    public function test_reduced_by_drops_a_denomination_that_is_used_up(): void
    {
        $reserve = CashReserve::fromCounts([25 => 1, 10 => 2]);

        $remaining = $reserve->reducedBy(ChangeDue::fromCounts([25 => 1]));

        self::assertSame([10 => 2], $remaining->counts());
    }
}
