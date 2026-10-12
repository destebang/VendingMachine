<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidCashReserveException;

/**
 * The physical coins and notes a machine holds in order to pay change, keyed by
 * denomination (integer minor units) with the number of units held as the
 * value.
 *
 * A value object rather than a raw array, so a reserve can never hold a
 * non-positive denomination or a negative quantity, and so "how much is in the
 * machine" has exactly one answer.
 */
final class CashReserve
{
    /**
     * @param array<int, int> $counts denomination in cents => number of units
     */
    private function __construct(
        private readonly array $counts,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<int, int> $counts denomination in cents => number of units
     */
    public static function fromCounts(array $counts): self
    {
        foreach ($counts as $denominationInCents => $quantity) {
            if ($denominationInCents <= 0) {
                throw InvalidCashReserveException::nonPositiveDenomination($denominationInCents);
            }

            if ($quantity < 0) {
                throw InvalidCashReserveException::negativeQuantity($denominationInCents, $quantity);
            }
        }

        return new self($counts);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    public function quantityOf(int $denominationInCents): int
    {
        return $this->counts[$denominationInCents] ?? 0;
    }

    /**
     * The total value held in the reserve.
     */
    public function total(): Money
    {
        $total = 0;
        foreach ($this->counts as $denominationInCents => $quantity) {
            $total += $denominationInCents * $quantity;
        }

        return Money::fromCents($total);
    }

    /**
     * @return array<int, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }
}
