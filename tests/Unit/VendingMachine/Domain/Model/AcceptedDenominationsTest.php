<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\MissingDenominationsException;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use PHPUnit\Framework\TestCase;

final class AcceptedDenominationsTest extends TestCase
{
    public function test_it_accepts_a_denomination_in_the_set(): void
    {
        $accepted = AcceptedDenominations::fromCents([5, 10, 25, 100]);

        self::assertTrue($accepted->accepts(Denomination::of(Money::fromCents(25))));
    }

    public function test_it_rejects_a_denomination_outside_the_set(): void
    {
        $accepted = AcceptedDenominations::fromCents([5, 10, 25, 100]);

        self::assertFalse($accepted->accepts(Denomination::of(Money::fromCents(50))));
    }

    public function test_it_serialises_to_minor_units_ascending(): void
    {
        $accepted = AcceptedDenominations::fromCents([100, 5, 25, 10]);

        self::assertSame([5, 10, 25, 100], $accepted->denominationsInCents());
    }

    public function test_it_normalises_duplicates_away(): void
    {
        $accepted = AcceptedDenominations::fromCents([25, 25, 10, 25]);

        self::assertSame([10, 25], $accepted->denominationsInCents());
    }

    public function test_it_can_be_built_from_denominations(): void
    {
        $accepted = AcceptedDenominations::fromDenominations([
            Denomination::of(Money::fromCents(50)),
            Denomination::of(Money::fromCents(5)),
        ]);

        self::assertSame([5, 50], $accepted->denominationsInCents());
    }

    public function test_an_empty_set_is_rejected(): void
    {
        $this->expectException(MissingDenominationsException::class);

        AcceptedDenominations::fromCents([]);
    }

    public function test_a_zero_denomination_is_rejected(): void
    {
        $this->expectException(InvalidDenominationException::class);

        AcceptedDenominations::fromCents([0, 25]);
    }
}
