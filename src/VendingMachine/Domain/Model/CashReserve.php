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
     * A reserve built from a stored count map. Denominations with no units are
     * not holdings, so zero-quantity lines are dropped; the result never claims
     * to be non-empty while holding nothing.
     *
     * @param array<int, int> $counts denomination in cents => number of units
     */
    public static function fromCounts(array $counts): self
    {
        $normalized = [];
        foreach ($counts as $denominationInCents => $quantity) {
            if ($denominationInCents <= 0) {
                throw InvalidCashReserveException::nonPositiveDenomination($denominationInCents);
            }

            if ($quantity < 0) {
                throw InvalidCashReserveException::negativeQuantity($denominationInCents, $quantity);
            }

            if ($quantity === 0) {
                continue;
            }

            $normalized[$denominationInCents] = $quantity;
        }

        return new self($normalized);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    public function quantityOf(Denomination $denomination): int
    {
        return $this->counts[$denomination->amount()->amountInCents()] ?? 0;
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
