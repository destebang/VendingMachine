<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidDenominationException;

/**
 * A single unit of physical currency (e.g. a coin or note) belonging to a
 * configurable set of allowed amounts. Kept generic on purpose: the set of
 * valid amounts is a parameter, not a hardcoded enum, so the same value
 * object can represent coins, notes, or any other denominated unit a future
 * machine might accept.
 */
final class Denomination
{
    private function __construct(
        private readonly Money $amount,
    ) {
    }

    /**
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
