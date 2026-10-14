<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\Shared\Domain\Event\DomainEvent;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;

/**
 * The version is the aggregate's optimistic-concurrency counter, and its
 * invariant is the point of this file: it advances exactly once per mutation
 * that records an event, stands still on rejections that record nothing, and
 * continues numbering from whatever a reconstituted aggregate was persisted
 * at — which is what keeps it in step with the version the repository's
 * persistence layer manages.
 */
final class VendingMachineVersionTest extends TestCase
{
    /** @var list<int> */
    private const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

    public function test_the_version_advances_once_per_recorded_mutation(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        self::assertSame(1, $machine->state()->version);

        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        self::assertSame(2, $machine->state()->version);

        $machine->returnInsertedFunds();

        self::assertSame(3, $machine->state()->version);

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
            $this->accepted(),
        );

        self::assertSame(4, $machine->state()->version);

        // A return with nothing inserted is a no-op in funds but still a
        // recorded fact, so the version still advances exactly once.
        $machine->returnInsertedFunds();

        self::assertSame(5, $machine->state()->version);
    }

    public function test_every_recorded_event_reports_the_version_its_mutation_left_the_machine_at(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->insertCoin(Denomination::of(Money::fromCents(10)));

        $events = $machine->releaseEvents();

        self::assertSame(
            [1, 2, 3],
            array_map(static fn(DomainEvent $event): int => $event->aggregateVersion(), $events),
        );
    }

    public function test_mutations_continue_numbering_from_the_persisted_version(): void
    {
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            $this->accepted(),
            InsertedFunds::none(),
            Stock::empty(),
            CashReserve::empty(),
            7,
        );

        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $events = $machine->releaseEvents();

        self::assertSame(8, $machine->state()->version);
        self::assertSame(8, $events[0]->aggregateVersion());
    }

    public function test_rejections_that_record_no_event_leave_the_version_untouched(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->releaseEvents();

        try {
            $machine->insertCoin(Denomination::of(Money::fromCents(50)));
            self::fail('Expected the inserted coin to be rejected.');
        } catch (InvalidDenominationException) {
            self::assertSame(1, $machine->state()->version);
        }

        try {
            $machine->selectItem('JUICE');
            self::fail('Expected the selection of an unstocked selector code to be rejected.');
        } catch (OutOfStockException) {
            self::assertSame(1, $machine->state()->version);
        }

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected for insufficient funds.');
        } catch (InsufficientFundsException) {
            self::assertSame(1, $machine->state()->version);
        }

        try {
            $machine->service(
                Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
                CashReserve::fromCounts([100 => 5]),
                $this->accepted(),
            );
            self::fail('Expected the service action to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException) {
            self::assertSame(1, $machine->state()->version);
        }

        self::assertSame([], $machine->releaseEvents());
    }

    private function accepted(): AcceptedDenominations
    {
        return AcceptedDenominations::fromCents(self::ACCEPTED_DENOMINATIONS_IN_CENTS);
    }

    private function stockedMachine(int $waterStock = 10): VendingMachine
    {
        return VendingMachine::reconstitute(
            VendingMachineId::generate(),
            $this->accepted(),
            InsertedFunds::none(),
            Stock::fromProducts([
                Product::create('WATER', Money::fromCents(65), $waterStock),
                Product::create('SODA', Money::fromCents(150), 10),
            ]),
            CashReserve::fromCounts([25 => 40, 10 => 40, 5 => 40]),
            0,
        );
    }
}
