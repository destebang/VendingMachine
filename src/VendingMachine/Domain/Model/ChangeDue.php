<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * The money a machine owes back to a customer after an overpayment, held as a
 * count per denomination so the exact coins handed back are known.
 *
 * A value object rather than a bare `Money`, because change is paid with real
 * coins: the machine has to say which denominations it gave back, not only how
 * much they add up to.
 */
final class ChangeDue
{
    /**
     * @param array<int, int> $counts denomination in cents => number of units
     */
    private function __construct(
        private readonly array $counts,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<int, int> $counts denomination in cents => number of units
     */
    public static function fromCounts(array $counts): self
    {
        $normalized = [];
        foreach ($counts as $denominationInCents => $quantity) {
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

    /**
     * The individual coins handed back, largest first, e.g. [25, 10].
     *
     * @return list<int>
     */
    public function denominationsInCents(): array
    {
        $counts = $this->counts;
        krsort($counts);

        $denominations = [];
        foreach ($counts as $denominationInCents => $quantity) {
            for ($unit = 0; $unit < $quantity; $unit++) {
                $denominations[] = $denominationInCents;
            }
        }

        return $denominations;
    }

    /**
     * The total value handed back.
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

    public function equals(self $other): bool
    {
        return $this->counts == $other->counts;
    }
}
