<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\MissingDenominationsException;

/**
 * The set of denominations a machine accepts from a customer.
 *
 * A property of the machine, not of a coin: the same coin can be legal tender
 * and still be rejected by a machine that does not take it. Holds a set of
 * `Denomination` value objects, normalises duplicates away, and refuses to be
 * empty — a machine must accept at least one denomination. The single source of
 * truth for the accept rule.
 */
final readonly class AcceptedDenominations
{
    /**
     * @param list<Denomination> $denominations
     */
    private function __construct(
        private array $denominations,
    ) {}

    /**
     * @param list<Denomination> $denominations
     */
    public static function fromDenominations(array $denominations): self
    {
        $byAmountInCents = [];
        foreach ($denominations as $denomination) {
            $byAmountInCents[$denomination->amount()->amountInCents()] = $denomination;
        }

        if ($byAmountInCents === []) {
            throw MissingDenominationsException::empty();
        }

        ksort($byAmountInCents);

        return new self(array_values($byAmountInCents));
    }

    /**
     * @param list<int> $amountsInCents
     */
    public static function fromCents(array $amountsInCents): self
    {
        $denominations = [];
        foreach ($amountsInCents as $amountInCents) {
            $denominations[] = Denomination::of(Money::fromCents($amountInCents));
        }

        return self::fromDenominations($denominations);
    }

    public function accepts(Denomination $denomination): bool
    {
        foreach ($this->denominations as $accepted) {
            if ($accepted->equals($denomination)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The accepted amounts in minor units, ascending, duplicates removed.
     *
     * @return list<int>
     */
    public function denominationsInCents(): array
    {
        $amounts = [];
        foreach ($this->denominations as $denomination) {
            $amounts[] = $denomination->amount()->amountInCents();
        }

        return $amounts;
    }
}
