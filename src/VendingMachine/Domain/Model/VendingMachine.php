<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;

/**
 * Write-side aggregate root for a single vending machine.
 *
 * It carries identity, the item stock, the coin reserve, any money a customer
 * has inserted so far, and the optimistic concurrency version expected by the
 * repository/persistence layer. Business behaviour is added use case by use
 * case on top of this skeleton.
 */
final class VendingMachine
{
    /**
     * Items currently loaded in the machine, keyed by selector code.
     *
     * @var array<string, Product>
     */
    private array $stock;

    /**
     * Physical coin/note reserve, keyed by denomination (integer minor
     * units) with the number of units held as the value.
     *
     * @var array<int, int>
     */
    private array $change;

    /**
     * @param array<string, Product> $stock
     * @param array<int, int>       $change
     */
    private function __construct(
        private readonly VendingMachineId $id,
        private Money $balance,
        private int $version,
        array $stock,
        array $change,
    ) {
        $this->stock = $stock;
        $this->change = $change;
    }

    /**
     * A brand-new, unstocked machine: empty stock, empty change, zero
     * balance.
     */
    public static function create(VendingMachineId $id): self
    {
        return new self($id, Money::zero(), 0, [], []);
    }

    /**
     * Reconstructs an aggregate from persisted state, e.g. when loaded by a
     * repository. Not for application code to call directly.
     *
     * @param array<string, Product> $stock
     * @param array<int, int>       $change
     */
    public static function reconstitute(
        VendingMachineId $id,
        Money $balance,
        int $version,
        array $stock,
        array $change,
    ): self {
        return new self($id, $balance, $version, $stock, $change);
    }

    public function id(): VendingMachineId
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function balance(): Money
    {
        return $this->balance;
    }

    /**
     * @return array<string, Product>
     */
    public function stock(): array
    {
        return $this->stock;
    }

    /**
     * @return array<int, int>
     */
    public function change(): array
    {
        return $this->change;
    }

    /**
     * Service action: replaces the whole item stock and coin reserve with the
     * state the technician declares. Replace, not additive — the technician
     * reports the true current physical state, never a delta.
     *
     * Rejected while a customer transaction is in flight, so a service visit
     * can never rewrite the machine out from under an inserted balance.
     *
     * @param array<string, Product> $stock
     * @param array<int, int>       $change denomination in cents => quantity
     */
    public function restock(array $stock, array $change): void
    {
        if ($this->hasPendingTransaction()) {
            throw PendingTransactionInProgressException::cannotRestock($this->id);
        }

        $this->stock = $stock;
        $this->change = $change;
    }

    /**
     * True while a customer has money inserted and the transaction has not
     * been completed or cancelled yet.
     */
    public function hasPendingTransaction(): bool
    {
        return !$this->balance->isZero();
    }

    /**
     * Bumps the optimistic-concurrency version. Called by the repository
     * after every successful write, never by business logic.
     */
    public function incrementVersion(): void
    {
        $this->version++;
    }
}
