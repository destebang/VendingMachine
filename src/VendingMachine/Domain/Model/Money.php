<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidMoneyAmountException;

/**
 * An immutable amount of money, always expressed in integer minor units
 * (cents), to avoid floating point arithmetic anywhere in the domain.
 */
final class Money
{
    private function __construct(
        private readonly int $amountInCents,
    ) {
        if ($this->amountInCents < 0) {
            throw InvalidMoneyAmountException::negativeAmount($this->amountInCents);
        }
    }

    public static function fromCents(int $amountInCents): self
    {
        return new self($amountInCents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function amountInCents(): int
    {
        return $this->amountInCents;
    }

    public function add(self $other): self
    {
        return new self($this->amountInCents + $other->amountInCents);
    }

    public function subtract(self $other): self
    {
        return new self($this->amountInCents - $other->amountInCents);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->amountInCents >= $other->amountInCents;
    }

    public function equals(self $other): bool
    {
        return $this->amountInCents === $other->amountInCents;
    }

    public function isZero(): bool
    {
        return $this->amountInCents === 0;
    }
}
