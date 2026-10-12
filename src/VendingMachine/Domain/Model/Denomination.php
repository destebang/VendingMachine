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
final class Denomination
{
    private function __construct(
        private readonly Money $amount,
    ) {
    }

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

    /**
     * A coin or note of the given amount, rejected unless it belongs to the
     * caller's allowed set.
     *
     * @param list<int> $allowedAmountsInCents
     */
    public static function fromCents(int $amountInCents, array $allowedAmountsInCents): self
    {
        if (!in_array($amountInCents, $allowedAmountsInCents, strict: true)) {
            throw InvalidDenominationException::notAllowed($amountInCents, $allowedAmountsInCents);
        }

        return new self(Money::fromCents($amountInCents));
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
