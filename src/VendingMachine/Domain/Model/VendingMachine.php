<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
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
    /**
     * The denominations this machine accepts from a customer: 5c, 10c, 25c and
     * 1 unit. A property of the machine rather than of a coin — the same coin
     * can be legal tender and still be rejected by a machine that does not take
     * it.
     *
     * @var list<int>
     */
    public const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

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
     * Records an accepted coin the customer has inserted. The only way inserted
     * funds grow.
     *
     * Rejected with a clear error — leaving inserted funds untouched — when the
     * machine does not take the coin's denomination.
     */
    public function insertCoin(Denomination $coin): void
    {
        $amountInCents = $coin->amount()->amountInCents();

        if (!in_array($amountInCents, self::ACCEPTED_DENOMINATIONS_IN_CENTS, strict: true)) {
            throw InvalidDenominationException::notAllowed($amountInCents, self::ACCEPTED_DENOMINATIONS_IN_CENTS);
        }

        $this->insertedFunds = $this->insertedFunds->plus($coin);
    }

    /**
     * Customer action: take back every coin inserted so far, ending the pending
     * transaction without a sale.
     *
     * The funds handed back are *exactly* the coins the customer inserted —
     * never a set drawn from the cash reserve — and the inserted balance is
     * reset to zero. A no-op when nothing has been inserted: the returned funds
     * are simply empty.
     */
    public function returnInsertedFunds(): InsertedFunds
    {
        $returnedFunds = $this->insertedFunds;
        $this->insertedFunds = InsertedFunds::none();

        return $returnedFunds;
    }

    /**
     * Customer action: selects the product with the given selector code, pays
     * for it out of the inserted funds and hands back any change due.
     *
     * The change is made from the cash reserve, never from the coins the
     * customer just inserted. On success the stock and cash reserve are
     * decremented and the inserted funds are reset; the vended product and the
     * change handed back are returned so the caller can report them.
     *
     * Rejected with a clear error, and a clear effect on the inserted funds:
     * - an unstocked or sold-out selector code (`OutOfStockException`) leaves the
     *   inserted funds untouched;
     * - a price above the inserted funds (`InsufficientFundsException`) leaves
     *   the inserted funds untouched so the customer can top up;
     * - change the reserve cannot make (`InsufficientChangeAvailableException`)
     *   returns every inserted coin, resetting the balance to zero.
     */
    public function selectItem(string $selectorCode): VendedItem
    {
        $product = $this->stock->product($selectorCode);

        if ($product === null || !$product->isInStock()) {
            throw OutOfStockException::forProduct($selectorCode);
        }

        $price = $product->price();
        $inserted = $this->insertedFunds->amount();

        if (!$inserted->isGreaterThanOrEqualTo($price)) {
            throw InsufficientFundsException::forProduct($selectorCode, $price, $inserted);
        }

        $changeDue = $inserted->subtract($price);

        if (!$this->cashReserve->canMake($changeDue)) {
            // Exact change only: the machine cannot make the required change, so
            // the sale is rejected and every inserted coin is handed back.
            $this->insertedFunds = InsertedFunds::none();

            throw InsufficientChangeAvailableException::forAmount($changeDue);
        }

        $change = $this->cashReserve->withdraw($changeDue);

        $this->cashReserve = $this->cashReserve->reducedBy($change);
        $product->decreaseStock();
        $this->insertedFunds = InsertedFunds::none();

        return new VendedItem($selectorCode, $price, $change);
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
