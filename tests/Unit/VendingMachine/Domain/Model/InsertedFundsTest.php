<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use PHPUnit\Framework\TestCase;

final class InsertedFundsTest extends TestCase
{
    public function test_none_holds_no_money(): void
    {
        $funds = InsertedFunds::none();

        self::assertTrue($funds->isEmpty());
        self::assertSame(0, $funds->amount()->amountInCents());
    }

    public function test_adding_funds_grows_the_amount(): void
    {
        $funds = InsertedFunds::none()
            ->plus(Money::fromCents(50))
            ->plus(Money::fromCents(25));

        self::assertFalse($funds->isEmpty());
        self::assertSame(75, $funds->amount()->amountInCents());
    }

    public function test_equality_is_by_amount(): void
    {
        self::assertTrue(
            InsertedFunds::of(Money::fromCents(50))->equals(InsertedFunds::of(Money::fromCents(50))),
        );
        self::assertFalse(
            InsertedFunds::of(Money::fromCents(50))->equals(InsertedFunds::none()),
        );
    }
}
