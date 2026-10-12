<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * Money a customer has put into a machine that has not yet been spent or
 * returned.
 *
 * Modelled as the collection of the individual coins inserted, not as a running
 * total, so that a return can hand back *exactly* the coins the customer put in
 * — never a set drawn from the machine's cash reserve. The amount is derived
 * from the coins, and a non-empty collection is exactly what makes a
 * transaction pending.
 */
final readonly class InsertedFunds
{
    /**
     * @param list<int> $denominationsInCents the literal coins inserted, in the
     *                                       order they were inserted
     */
    private function __construct(
        private array $denominationsInCents,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param list<int> $denominationsInCents
     */
    public static function fromDenominations(array $denominationsInCents): self
    {
        return new self(array_values($denominationsInCents));
    }

    public function isEmpty(): bool
    {
        return $this->denominationsInCents === [];
    }

    /**
     * The total value inserted so far.
     */
    public function amount(): Money
    {
        return Money::fromCents((int) array_sum($this->denominationsInCents));
    }

    /**
     * The literal coins inserted, in the order they were inserted, e.g. [25, 10].
     *
     * @return list<int>
     */
    public function denominationsInCents(): array
    {
        return $this->denominationsInCents;
    }

    public function plus(Denomination $coin): self
    {
        $denominations = $this->denominationsInCents;
        $denominations[] = $coin->amount()->amountInCents();

        return new self($denominations);
    }

    public function equals(self $other): bool
    {
        return $this->denominationsInCents === $other->denominationsInCents;
    }
}
