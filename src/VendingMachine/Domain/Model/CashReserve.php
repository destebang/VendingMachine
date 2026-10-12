<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
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
final readonly class CashReserve
{
    /**
     * @param array<int, int> $counts denomination in cents => number of units
     */
    private function __construct(
        private array $counts,
    ) {}

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

    /**
     * Whether the reserve can pay exactly `$amount` with the coins it holds.
     */
    public function canMake(Money $amount): bool
    {
        return self::search($this->denominationsDescending(), $this->counts, 0, $amount->amountInCents()) !== null;
    }

    /**
     * The coins that make up `$amount`, withdrawn from the reserve and handed
     * back as change, preferring the largest denominations.
     *
     * Rejected with a clear error — leaving the reserve untouched — when the
     * reserve cannot make the amount.
     */
    public function withdraw(Money $amount): ChangeDue
    {
        $counts = self::search($this->denominationsDescending(), $this->counts, 0, $amount->amountInCents());

        if ($counts === null) {
            throw InsufficientChangeAvailableException::forAmount($amount);
        }

        return ChangeDue::fromCounts($counts);
    }

    /**
     * The reserve that remains after handing back `$change`.
     */
    public function reducedBy(ChangeDue $change): self
    {
        $counts = $this->counts;
        foreach ($change->counts() as $denominationInCents => $quantity) {
            $counts[$denominationInCents] = ($counts[$denominationInCents] ?? 0) - $quantity;
        }

        return self::fromCounts($counts);
    }

    /**
     * @return list<int>
     */
    private function denominationsDescending(): array
    {
        $denominations = array_keys($this->counts);
        rsort($denominations);

        return $denominations;
    }

    /**
     * Bounded search for a set of held coins summing exactly to `$remaining`. A
     * greedy "largest coin first" walk is not enough with a limited supply of
     * each coin — it can fail where a solution still exists — so this backtracks
     * over the denominations, largest first.
     *
     * @param list<int>       $denominations largest first
     * @param array<int, int> $available     denomination in cents => units held
     *
     * @return array<int, int>|null denomination in cents => units to pay, or null when no set of held coins adds up
     */
    private static function search(array $denominations, array $available, int $index, int $remaining): ?array
    {
        if ($remaining === 0) {
            return [];
        }

        if ($index >= count($denominations)) {
            return null;
        }

        $denominationInCents = $denominations[$index];
        $mostWeCanTake = min($available[$denominationInCents] ?? 0, intdiv($remaining, $denominationInCents));

        for ($take = $mostWeCanTake; $take >= 0; $take--) {
            $rest = self::search($denominations, $available, $index + 1, $remaining - ($take * $denominationInCents));

            if ($rest !== null) {
                if ($take > 0) {
                    $rest[$denominationInCents] = $take;
                }

                return $rest;
            }
        }

        return null;
    }
}
