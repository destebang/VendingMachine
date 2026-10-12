<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use PHPUnit\Framework\TestCase;

final class DenominationTest extends TestCase
{
    private const ALLOWED_COINS = [5, 10, 25, 100];

    public function test_of_names_a_denomination_without_an_allow_list(): void
    {
        $denomination = Denomination::of(Money::fromCents(25));

        self::assertSame(25, $denomination->amount()->amountInCents());
    }

    public function test_of_rejects_a_zero_amount(): void
    {
        $this->expectException(InvalidDenominationException::class);

        Denomination::of(Money::zero());
    }

    public function test_it_accepts_an_allowed_amount(): void
    {
        $denomination = Denomination::fromCents(25, self::ALLOWED_COINS);

        self::assertSame(25, $denomination->amount()->amountInCents());
    }

    public function test_it_rejects_an_amount_outside_the_allow_list(): void
    {
        $this->expectException(InvalidDenominationException::class);

        Denomination::fromCents(50, self::ALLOWED_COINS);
    }

    public function test_two_denominations_of_the_same_amount_are_equal(): void
    {
        self::assertTrue(
            Denomination::fromCents(25, self::ALLOWED_COINS)
                ->equals(Denomination::fromCents(25, self::ALLOWED_COINS)),
        );
    }
}
