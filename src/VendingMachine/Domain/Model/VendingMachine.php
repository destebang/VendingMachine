<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;

/**
 * Write-side aggregate root for a single vending machine.
 *
 * It carries identity, the stock it holds, the cash reserve it pays change
 * from, the funds a customer has inserted so far, and the optimistic
 * concurrency version expected by the repository/persistence layer. Each
 * collection is a value object rather than a raw array, so its invariants and
 * shape live here instead of being re-derived by the projection and the
 * repository.
 *
 * The aggregate exposes behaviour, not its fields: everything the outside world
 * reads comes out through a single `state()` snapshot.
 */
final class VendingMachine
{
    private function __construct(
        private readonly VendingMachineId $id,
        private InsertedFunds $insertedFunds,
        private Stock $stock,
        private CashReserve $cashReserve,
        private int $version,
    ) {
    }

    /**
     * A brand-new, unstocked machine: empty stock, empty reserve, no inserted
     * funds.
     */
    public static function create(VendingMachineId $id): self
    {
        return new self($id, InsertedFunds::none(), Stock::empty(), CashReserve::empty(), 0);
    }

    /**
     * Reconstructs an aggregate from persisted state, e.g. when loaded by a
     * repository. Not for application code to call directly.
     */
    public static function reconstitute(
        VendingMachineId $id,
        InsertedFunds $insertedFunds,
        Stock $stock,
        CashReserve $cashReserve,
        int $version,
    ): self {
        return new self($id, $insertedFunds, $stock, $cashReserve, $version);
    }

    /**
     * Snapshot of everything the machine currently holds. The one way out of
     * the aggregate for persistence and the read-model projection.
     */
    public function state(): VendingMachineState
    {
        return new VendingMachineState(
            $this->id,
            $this->stock,
            $this->cashReserve,
            $this->insertedFunds,
            $this->version,
        );
    }

    /**
     * Records money the customer has put into the machine. The only way
     * inserted funds grow.
     */
    public function insertFunds(Money $amount): void
    {
        $this->insertedFunds = $this->insertedFunds->plus($amount);
    }

    /**
     * Service action: replaces the whole stock and cash reserve with the state
     * the technician declares. Replace, not additive — the technician reports
     * the true current physical state, never a delta.
     *
     * Rejected while a customer transaction is in flight, so a service visit
     * can never rewrite the machine out from under inserted funds.
     */
    public function service(Stock $stock, CashReserve $cashReserve): void
    {
        if ($this->hasPendingTransaction()) {
            throw PendingTransactionInProgressException::cannotService($this->id);
        }

        $this->stock = $stock;
        $this->cashReserve = $cashReserve;
    }

    /**
     * True while a customer has inserted funds and the transaction has not
     * been completed or cancelled yet. Internal invariant, not part of the
     * aggregate's public surface.
     */
    private function hasPendingTransaction(): bool
    {
        return !$this->insertedFunds->isEmpty();
    }
}
