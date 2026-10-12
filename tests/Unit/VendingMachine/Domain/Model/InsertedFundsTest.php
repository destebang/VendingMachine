<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Model\Denomination;
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
        self::assertSame([], $funds->denominationsInCents());
    }

    public function test_adding_coins_records_each_denomination_and_grows_the_amount(): void
    {
        $funds = InsertedFunds::none()
            ->plus(Denomination::of(Money::fromCents(25)))
            ->plus(Denomination::of(Money::fromCents(10)));

        self::assertFalse($funds->isEmpty());
        self::assertSame(35, $funds->amount()->amountInCents());
        self::assertSame([25, 10], $funds->denominationsInCents());
    }

    public function test_equality_is_by_the_coins_held(): void
    {
        self::assertTrue(
            InsertedFunds::fromDenominations([25, 25])->equals(InsertedFunds::fromDenominations([25, 25])),
        );
        self::assertFalse(
            InsertedFunds::fromDenominations([50])->equals(InsertedFunds::fromDenominations([25, 25])),
        );
        self::assertFalse(
            InsertedFunds::fromDenominations([50])->equals(InsertedFunds::none()),
        );
    }
}
