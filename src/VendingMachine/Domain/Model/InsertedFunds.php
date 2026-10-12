<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * Money a customer has put into a machine that has not yet been spent or
 * returned.
 *
 * Modelling this explicitly (rather than as a bare `Money`) gives the customer
 * side of the machine a name: a non-empty value is exactly what makes a
 * transaction pending, and inserting funds is the only way it grows.
 */
final class InsertedFunds
{
    private function __construct(
        private readonly Money $amount,
    ) {
    }

    public static function none(): self
    {
        return new self(Money::zero());
    }

    public static function of(Money $amount): self
    {
        return new self($amount);
    }

    public function isEmpty(): bool
    {
        return $this->amount->isZero();
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function plus(Money $added): self
    {
        return new self($this->amount->add($added));
    }

    public function equals(self $other): bool
    {
        return $this->amount->equals($other->amount);
    }
}
