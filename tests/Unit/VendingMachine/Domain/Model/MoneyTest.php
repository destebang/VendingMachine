<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidMoneyAmountException;
use App\VendingMachine\Domain\Model\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_adds_two_amounts(): void
    {
        $sum = Money::fromCents(65)->add(Money::fromCents(35));

        self::assertSame(100, $sum->amountInCents());
    }

    public function test_it_subtracts_two_amounts(): void
    {
        $difference = Money::fromCents(100)->subtract(Money::fromCents(65));

        self::assertSame(35, $difference->amountInCents());
    }

    public function test_two_equal_amounts_are_equal(): void
    {
        self::assertTrue(Money::fromCents(100)->equals(Money::fromCents(100)));
        self::assertFalse(Money::fromCents(100)->equals(Money::fromCents(99)));
    }

    public function test_it_compares_amounts(): void
    {
        self::assertTrue(Money::fromCents(100)->isGreaterThanOrEqualTo(Money::fromCents(100)));
        self::assertTrue(Money::fromCents(150)->isGreaterThanOrEqualTo(Money::fromCents(100)));
        self::assertFalse(Money::fromCents(50)->isGreaterThanOrEqualTo(Money::fromCents(100)));
    }

    public function test_zero_is_zero(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse(Money::fromCents(1)->isZero());
    }

    public function test_it_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromCents(-1);
    }

    public function test_subtracting_below_zero_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromCents(10)->subtract(Money::fromCents(20));
    }
}
