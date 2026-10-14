<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidDenominationException;

/**
 * A single unit of physical currency (a coin or note), described by its amount.
 *
 * Kept generic on purpose: the amount is data, not a hardcoded enum, so the
 * same value object can represent coins, notes, or any other denominated unit.
 * Whether a given amount is *accepted* by a particular machine is a separate
 * concern from what the coin is worth.
 */
final readonly class Denomination
{
    private function __construct(
        private Money $amount,
    ) {}

    /**
     * A coin or note of the given amount, without judging whether it is
     * accepted anywhere.
     */
    public static function of(Money $amount): self
    {
        if ($amount->isZero()) {
            throw InvalidDenominationException::notPositive($amount->amountInCents());
        }

        return new self($amount);
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount->equals($other->amount);
    }
}
