<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\Shared\Domain\Event\DomainEvent;
use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\InsertedFundsReturned;
use App\VendingMachine\Domain\Event\InsertedFundsReturnedDueToInsufficientChange;
use App\VendingMachine\Domain\Event\ItemVended;
use App\VendingMachine\Domain\Event\VendingMachineProvisioned;
use App\VendingMachine\Domain\Event\VendingMachineServiced;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;

/**
 * Write-side aggregate root for a single vending machine.
 *
 * It carries identity, the denominations it accepts, the stock it holds, the
 * cash reserve it pays change from, the funds a customer has inserted so far,
 * and the optimistic concurrency version expected by the
 * repository/persistence layer. Each collection is a value object rather than a
 * raw array, so its invariants and shape live here instead of being re-derived
 * by the projection and the repository.
 *
 * The aggregate exposes behaviour, not its fields: everything the outside world
 * reads comes out through a single `state()` snapshot.
 *
 * Every mutation records a domain event describing it. The aggregate does not
 * publish them: it only releases what it has recorded, so the application
 * layer can decide when and how to publish.
 */
final class VendingMachine
{
    /**
     * The domain events recorded since the last release, in the order the
     * mutations happened.
     *
     * @var list<DomainEvent>
     */
    private array $recordedEvents = [];

    private function __construct(
        private readonly VendingMachineId $id,
        private AcceptedDenominations $acceptedDenominations,
        private InsertedFunds $insertedFunds,
        private Stock $stock,
        private CashReserve $cashReserve,
        private int $version,
    ) {}

    /**
     * A brand-new, unstocked machine accepting the given denominations: empty
     * stock, empty reserve, no inserted funds. Records the machine's first
     * event.
     */
    public static function create(VendingMachineId $id, AcceptedDenominations $acceptedDenominations): self
    {
        $vendingMachine = new self(
            $id,
            $acceptedDenominations,
            InsertedFunds::none(),
            Stock::empty(),
            CashReserve::empty(),
            0,
        );
        $vendingMachine->record(new VendingMachineProvisioned($id, $vendingMachine->state()));

        return $vendingMachine;
    }

    /**
     * Reconstructs an aggregate from persisted state, e.g. when loaded by a
     * repository. Not for application code to call directly. Records no event:
     * the state was produced by mutations that already happened.
     */
    public static function reconstitute(
        VendingMachineId $id,
        AcceptedDenominations $acceptedDenominations,
        InsertedFunds $insertedFunds,
        Stock $stock,
        CashReserve $cashReserve,
        int $version,
    ): self {
        return new self($id, $acceptedDenominations, $insertedFunds, $stock, $cashReserve, $version);
    }

    /**
     * Snapshot of everything the machine currently holds. The one way out of
     * the aggregate for persistence and the read-model projection.
     */
    public function state(): VendingMachineState
    {
        return new VendingMachineState(
            $this->id,
            $this->acceptedDenominations,
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
        if (!$this->acceptedDenominations->accepts($coin)) {
            throw InvalidDenominationException::notAllowed(
                $coin->amount()->amountInCents(),
                $this->acceptedDenominations->denominationsInCents(),
            );
        }

        $this->insertedFunds = $this->insertedFunds->plus($coin);

        $this->record(new CoinInserted($this->id, $this->state(), $coin));
    }

    /**
     * Customer action: take back every coin inserted so far, ending the pending
     * transaction without a sale.
     *
     * The funds handed back are *exactly* the coins the customer inserted —
     * never a set drawn from the cash reserve — and the inserted balance is
     * reset to zero. A no-op when nothing has been inserted: the returned funds
     * are simply empty, though the action still records its event.
     */
    public function returnInsertedFunds(): InsertedFunds
    {
        $returnedFunds = $this->insertedFunds;
        $this->insertedFunds = InsertedFunds::none();

        $this->record(new InsertedFundsReturned($this->id, $this->state(), $returnedFunds));

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
     *
     * A success records `ItemVended`, and an exact-change rejection records
     * `InsertedFundsReturnedDueToInsufficientChange` before it throws. The
     * `OutOfStock` and `InsufficientFunds` rejections throw before any mutation
     * and record nothing.
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
            // the sale is rejected and every inserted coin is handed back. The
            // rejection is recorded before it is thrown — the machine has
            // already changed.
            $returnedFunds = $this->insertedFunds;
            $this->insertedFunds = InsertedFunds::none();

            $this->record(new InsertedFundsReturnedDueToInsufficientChange($this->id, $this->state(), $returnedFunds));

            throw InsufficientChangeAvailableException::forAmount($changeDue);
        }

        $change = $this->cashReserve->withdraw($changeDue);

        $this->cashReserve = $this->cashReserve->reducedBy($change);
        $product->decreaseStock();
        $this->insertedFunds = InsertedFunds::none();

        $vendedItem = new VendedItem($selectorCode, $price, $change);
        $this->record(new ItemVended($this->id, $this->state(), $vendedItem));

        return $vendedItem;
    }

    /**
     * Service action: replaces the whole stock, cash reserve and accepted
     * denominations with the state the technician declares. Replace, not
     * additive — the technician reports the true current physical state (and the
     * coin set the machine is reconfigured to take), never a delta.
     *
     * Rejected while a customer transaction is in flight, so a service visit
     * can never rewrite the machine out from under inserted funds. A rejected
     * service throws before any mutation and records nothing.
     */
    public function service(Stock $stock, CashReserve $cashReserve, AcceptedDenominations $acceptedDenominations): void
    {
        if ($this->hasPendingTransaction()) {
            throw PendingTransactionInProgressException::cannotService($this->id);
        }

        $this->stock = $stock;
        $this->cashReserve = $cashReserve;
        $this->acceptedDenominations = $acceptedDenominations;

        $this->record(new VendingMachineServiced($this->id, $this->state()));
    }

    /**
     * Returns the domain events recorded since the last release, in the order
     * they happened, and clears the list so each event is handed out exactly
     * once.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
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

    private function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
