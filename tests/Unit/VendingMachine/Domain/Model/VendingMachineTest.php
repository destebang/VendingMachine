<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

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
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VendingMachineTest extends TestCase
{
    /** @var list<int> */
    private const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

    public function test_a_newly_created_machine_starts_empty(): void
    {
        $state = VendingMachine::create(VendingMachineId::generate(), $this->accepted())->state();

        // Provisioning is the machine's first recorded mutation, so it is left at version 1.
        self::assertSame(1, $state->version);
        self::assertTrue($state->stock->isEmpty());
        self::assertTrue($state->cashReserve->isEmpty());
        self::assertTrue($state->insertedFunds->isEmpty());
    }

    public function test_a_newly_created_machine_is_configured_with_the_accepted_denominations(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), AcceptedDenominations::fromCents([10, 50]));

        self::assertSame([10, 50], $machine->state()->acceptedDenominations->denominationsInCents());
    }

    public function test_reconstitute_restores_identity_version_and_state(): void
    {
        $id = VendingMachineId::generate();

        $machine = VendingMachine::reconstitute(
            $id,
            $this->accepted(),
            InsertedFunds::fromDenominations([25, 25]),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 3)]),
            CashReserve::fromCounts([100 => 2]),
            7,
        );

        $state = $machine->state();

        self::assertTrue($state->id->equals($id));
        self::assertSame(7, $state->version);
        self::assertSame(self::ACCEPTED_DENOMINATIONS_IN_CENTS, $state->acceptedDenominations->denominationsInCents());
        self::assertSame(3, $state->stock->quantityOf('WATER'));
        self::assertSame(2, $state->cashReserve->quantityOf(Denomination::of(Money::fromCents(100))));
        self::assertSame(50, $state->insertedFunds->amount()->amountInCents());
    }

    public function test_service_replaces_rather_than_adds_to_stock_and_reserve(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
            $this->accepted(),
        );

        $machine->service(
            Stock::fromProducts([Product::create('CHIPS', Money::fromCents(120), 2)]),
            CashReserve::fromCounts([50 => 1]),
            $this->accepted(),
        );

        $state = $machine->state();

        self::assertSame(['CHIPS'], array_keys($state->stock->products()));
        self::assertSame(2, $state->stock->quantityOf('CHIPS'));
        self::assertSame([50 => 1], $state->cashReserve->counts());
    }

    public function test_service_reconfigures_the_accepted_denominations(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
            AcceptedDenominations::fromCents([10, 50]),
        );

        self::assertSame([10, 50], $machine->state()->acceptedDenominations->denominationsInCents());
    }

    public function test_service_is_rejected_while_a_transaction_is_pending(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $this->expectException(PendingTransactionInProgressException::class);

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
            $this->accepted(),
        );
    }

    public function test_service_leaves_state_untouched_when_rejected(): void
    {
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            $this->accepted(),
            InsertedFunds::fromDenominations([25, 25]),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 3)]),
            CashReserve::fromCounts([100 => 2]),
            0,
        );

        try {
            $machine->service(
                Stock::fromProducts([Product::create('CHIPS', Money::fromCents(120), 10)]),
                CashReserve::fromCounts([50 => 9]),
                $this->accepted(),
            );
            self::fail('Expected the service action to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException) {
            $state = $machine->state();

            self::assertSame(['WATER'], array_keys($state->stock->products()));
            self::assertSame([100 => 2], $state->cashReserve->counts());
        }
    }

    public function test_inserting_accepted_coins_accumulates(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->insertCoin(Denomination::of(Money::fromCents(100)));

        self::assertSame(150, $machine->state()->insertedFunds->amount()->amountInCents());
    }

    /**
     * @param int $amountInCents
     */
    #[DataProvider('acceptedDenominations')]
    public function test_every_accepted_denomination_is_recorded(int $amountInCents): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $machine->insertCoin(Denomination::of(Money::fromCents($amountInCents)));

        self::assertSame($amountInCents, $machine->state()->insertedFunds->amount()->amountInCents());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function acceptedDenominations(): iterable
    {
        yield '5 cents' => [5];
        yield '10 cents' => [10];
        yield '25 cents' => [25];
        yield '1 unit' => [100];
    }

    public function test_inserting_a_denomination_the_machine_does_not_accept_is_rejected(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $this->expectException(InvalidDenominationException::class);

        $machine->insertCoin(Denomination::of(Money::fromCents(50)));
    }

    public function test_a_rejected_denomination_leaves_the_balance_untouched(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        try {
            $machine->insertCoin(Denomination::of(Money::fromCents(50)));
            self::fail('Expected the inserted coin to be rejected.');
        } catch (InvalidDenominationException) {
            self::assertSame(25, $machine->state()->insertedFunds->amount()->amountInCents());
        }
    }

    public function test_a_machine_only_accepts_the_denominations_it_is_configured_with(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), AcceptedDenominations::fromCents([10, 50]));

        $machine->insertCoin(Denomination::of(Money::fromCents(10)));
        $machine->insertCoin(Denomination::of(Money::fromCents(50)));

        self::assertSame(60, $machine->state()->insertedFunds->amount()->amountInCents());
    }

    public function test_the_rejection_reports_the_machines_own_accepted_set(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), AcceptedDenominations::fromCents([10, 50]));

        try {
            $machine->insertCoin(Denomination::of(Money::fromCents(25)));
            self::fail('Expected the inserted coin to be rejected.');
        } catch (InvalidDenominationException $exception) {
            self::assertStringContainsString('10, 50', $exception->getMessage());
        }
    }

    public function test_returning_inserted_funds_hands_back_the_exact_coins_and_resets_the_balance(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->insertCoin(Denomination::of(Money::fromCents(10)));
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $returnedFunds = $machine->returnInsertedFunds();

        self::assertSame([25, 10, 25], $returnedFunds->denominationsInCents());
        self::assertSame(60, $returnedFunds->amount()->amountInCents());
        self::assertTrue($machine->state()->insertedFunds->isEmpty());
        self::assertSame(0, $machine->state()->insertedFunds->amount()->amountInCents());
    }

    public function test_returning_funds_never_touches_the_cash_reserve(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $machine->returnInsertedFunds();

        self::assertSame([25 => 40, 10 => 40, 5 => 40], $machine->state()->cashReserve->counts());
    }

    public function test_returning_with_nothing_inserted_is_a_no_op(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $returnedFunds = $machine->returnInsertedFunds();

        self::assertTrue($returnedFunds->isEmpty());
        self::assertSame([], $returnedFunds->denominationsInCents());
        self::assertSame(0, $machine->state()->insertedFunds->amount()->amountInCents());
    }

    public function test_a_return_ends_the_pending_transaction_so_the_machine_can_be_serviced(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $machine->returnInsertedFunds();

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
            $this->accepted(),
        );

        self::assertSame(10, $machine->state()->stock->quantityOf('WATER'));
    }

    public function test_selecting_a_product_with_exact_funds_vends_it_and_gives_no_change(): void
    {
        $machine = $this->stockedMachine();

        $machine->insertCoin(Denomination::of(Money::fromCents(100)));
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $vendedItem = $machine->selectItem('SODA');
        $state = $machine->state();

        self::assertSame('SODA', $vendedItem->selectorCode);
        self::assertSame(150, $vendedItem->price->amountInCents());
        self::assertTrue($vendedItem->changeDue->isEmpty());
        self::assertSame(9, $state->stock->quantityOf('SODA'));
        self::assertSame([25 => 40, 10 => 40, 5 => 40], $state->cashReserve->counts());
        self::assertSame(0, $state->insertedFunds->amount()->amountInCents());
    }

    public function test_change_is_made_from_the_reserve_largest_denomination_first(): void
    {
        $machine = $this->stockedMachine();

        $machine->insertCoin(Denomination::of(Money::fromCents(100)));

        $vendedItem = $machine->selectItem('WATER');
        $state = $machine->state();

        self::assertSame('WATER', $vendedItem->selectorCode);
        self::assertSame(65, $vendedItem->price->amountInCents());
        self::assertSame(35, $vendedItem->changeDue->total()->amountInCents());
        self::assertSame([25, 10], $vendedItem->changeDue->denominationsInCents());
        self::assertSame(9, $state->stock->quantityOf('WATER'));
        self::assertSame([25 => 39, 10 => 39, 5 => 40], $state->cashReserve->counts());
        self::assertSame(0, $state->insertedFunds->amount()->amountInCents());
    }

    public function test_selecting_with_insufficient_funds_is_rejected_and_keeps_the_balance(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected for insufficient funds.');
        } catch (InsufficientFundsException) {
            $state = $machine->state();

            self::assertSame(25, $state->insertedFunds->amount()->amountInCents());
            self::assertSame(10, $state->stock->quantityOf('WATER'));
            self::assertSame([25 => 40, 10 => 40, 5 => 40], $state->cashReserve->counts());
        }
    }

    public function test_selecting_an_out_of_stock_product_is_rejected_and_keeps_the_balance(): void
    {
        $machine = $this->stockedMachine(waterStock: 0);
        $machine->insertCoin(Denomination::of(Money::fromCents(100)));

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected as out of stock.');
        } catch (OutOfStockException) {
            $state = $machine->state();

            self::assertSame(100, $state->insertedFunds->amount()->amountInCents());
            self::assertSame(0, $state->stock->quantityOf('WATER'));
        }
    }

    public function test_selecting_an_unstocked_selector_code_is_rejected_as_out_of_stock(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(100)));

        try {
            $machine->selectItem('JUICE');
            self::fail('Expected the selection of an unstocked selector code to be rejected.');
        } catch (OutOfStockException) {
            self::assertSame(100, $machine->state()->insertedFunds->amount()->amountInCents());
        }
    }

    public function test_selecting_when_the_reserve_cannot_make_change_is_rejected_and_refunds(): void
    {
        // The customer's own coins (25 + 25 + 25 + 10 + 10 + 5) could make the 35
        // cents of change, but change is only ever paid from the reserve, which is
        // empty — so the sale is rejected and the whole 100 cents is handed back.
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            $this->accepted(),
            InsertedFunds::none(),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::empty(),
            0,
        );

        foreach ([25, 25, 25, 10, 10, 5] as $denominationInCents) {
            $machine->insertCoin(Denomination::of(Money::fromCents($denominationInCents)));
        }

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected for insufficient change.');
        } catch (InsufficientChangeAvailableException) {
            $state = $machine->state();

            self::assertSame(0, $state->insertedFunds->amount()->amountInCents());
            self::assertSame(10, $state->stock->quantityOf('WATER'));
            self::assertTrue($state->cashReserve->isEmpty());
        }
    }

    public function test_create_records_a_vending_machine_provisioned_event(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());

        $events = $machine->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(VendingMachineProvisioned::class, $events[0]);
        self::assertSame($machine->state()->id->toString(), $events[0]->aggregateId());
        self::assertSame(1, $events[0]->aggregateVersion());
    }

    public function test_reconstituting_from_persisted_state_records_no_events(): void
    {
        self::assertSame([], $this->stockedMachine()->releaseEvents());
    }

    public function test_release_events_returns_every_recorded_event_and_clears_them(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $events = $machine->releaseEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(VendingMachineProvisioned::class, $events[0]);
        self::assertInstanceOf(CoinInserted::class, $events[1]);
        self::assertSame([], $machine->releaseEvents());
    }

    public function test_service_records_a_vending_machine_serviced_event(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->releaseEvents();

        $machine->service(
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::fromCounts([100 => 5]),
            $this->accepted(),
        );

        $events = $machine->releaseEvents();

        self::assertCount(1, $events);
        /** @var VendingMachineServiced $event */
        $event = $events[0];
        self::assertInstanceOf(VendingMachineServiced::class, $event);
        self::assertSame(10, $event->state->stock->quantityOf('WATER'));
        self::assertSame([100 => 5], $event->state->cashReserve->counts());
    }

    public function test_a_service_rejected_while_a_transaction_is_pending_records_no_event(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->releaseEvents();

        try {
            $machine->service(
                Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
                CashReserve::fromCounts([100 => 5]),
                $this->accepted(),
            );
            self::fail('Expected the service action to be rejected while a transaction is pending.');
        } catch (PendingTransactionInProgressException) {
            self::assertSame([], $machine->releaseEvents());
        }
    }

    public function test_inserting_an_accepted_coin_records_a_coin_inserted_event(): void
    {
        $machine = $this->stockedMachine();

        $machine->insertCoin(Denomination::of(Money::fromCents(25)));

        $events = $machine->releaseEvents();

        self::assertCount(1, $events);
        /** @var CoinInserted $event */
        $event = $events[0];
        self::assertInstanceOf(CoinInserted::class, $event);
        self::assertSame(25, $event->denomination->amount()->amountInCents());
        self::assertSame(25, $event->state->insertedFunds->amount()->amountInCents());
    }

    public function test_a_rejected_denomination_records_no_event(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->releaseEvents();

        try {
            $machine->insertCoin(Denomination::of(Money::fromCents(50)));
            self::fail('Expected the inserted coin to be rejected.');
        } catch (InvalidDenominationException) {
            self::assertSame([], $machine->releaseEvents());
        }
    }

    public function test_returning_inserted_funds_records_an_inserted_funds_returned_event(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->insertCoin(Denomination::of(Money::fromCents(10)));
        $machine->releaseEvents();

        $machine->returnInsertedFunds();

        $events = $machine->releaseEvents();

        self::assertCount(1, $events);
        /** @var InsertedFundsReturned $event */
        $event = $events[0];
        self::assertInstanceOf(InsertedFundsReturned::class, $event);
        self::assertSame([25, 10], $event->returnedFunds->denominationsInCents());
        self::assertSame(0, $event->state->insertedFunds->amount()->amountInCents());
    }

    public function test_returning_with_nothing_inserted_still_records_the_return(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate(), $this->accepted());
        $machine->releaseEvents();

        $machine->returnInsertedFunds();

        $events = $machine->releaseEvents();

        self::assertCount(1, $events);
        /** @var InsertedFundsReturned $event */
        $event = $events[0];
        self::assertInstanceOf(InsertedFundsReturned::class, $event);
        self::assertTrue($event->returnedFunds->isEmpty());
    }

    public function test_a_successful_selection_records_an_item_vended_event(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(100)));
        $machine->releaseEvents();

        $vendedItem = $machine->selectItem('WATER');

        $events = $machine->releaseEvents();

        self::assertCount(1, $events);
        /** @var ItemVended $event */
        $event = $events[0];
        self::assertInstanceOf(ItemVended::class, $event);
        self::assertSame($vendedItem, $event->vendedItem);
        self::assertSame(9, $event->state->stock->quantityOf('WATER'));
        self::assertSame(0, $event->state->insertedFunds->amount()->amountInCents());
    }

    public function test_an_insufficient_funds_selection_records_no_event(): void
    {
        $machine = $this->stockedMachine();
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $machine->releaseEvents();

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected for insufficient funds.');
        } catch (InsufficientFundsException) {
            self::assertSame([], $machine->releaseEvents());
        }
    }

    public function test_an_out_of_stock_selection_records_no_event(): void
    {
        $machine = $this->stockedMachine(waterStock: 0);
        $machine->insertCoin(Denomination::of(Money::fromCents(100)));
        $machine->releaseEvents();

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected as out of stock.');
        } catch (OutOfStockException) {
            self::assertSame([], $machine->releaseEvents());
        }
    }

    public function test_an_exact_change_rejection_records_funds_returned_before_throwing(): void
    {
        $machine = VendingMachine::reconstitute(
            VendingMachineId::generate(),
            $this->accepted(),
            InsertedFunds::none(),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 10)]),
            CashReserve::empty(),
            0,
        );

        foreach ([25, 25, 25, 10, 10, 5] as $denominationInCents) {
            $machine->insertCoin(Denomination::of(Money::fromCents($denominationInCents)));
        }
        $machine->releaseEvents();

        try {
            $machine->selectItem('WATER');
            self::fail('Expected the selection to be rejected for insufficient change.');
        } catch (InsufficientChangeAvailableException) {
            $events = $machine->releaseEvents();

            self::assertCount(1, $events);
            /** @var InsertedFundsReturnedDueToInsufficientChange $event */
            $event = $events[0];
            self::assertInstanceOf(InsertedFundsReturnedDueToInsufficientChange::class, $event);
            self::assertSame('WATER', $event->selectorCode);
            self::assertSame([25, 25, 25, 10, 10, 5], $event->returnedFunds->denominationsInCents());
            self::assertSame(0, $event->state->insertedFunds->amount()->amountInCents());
            self::assertSame(10, $event->state->stock->quantityOf('WATER'));

            // The rejection is a recorded mutation: six inserts plus the
            // recorded refund leave the machine at version 7, and the event
            // names exactly that version.
            self::assertSame(7, $event->aggregateVersion());
            self::assertSame(7, $machine->state()->version);
        }
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
