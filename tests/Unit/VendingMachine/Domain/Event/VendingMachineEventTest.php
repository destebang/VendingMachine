<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\InsertedFundsReturned;
use App\VendingMachine\Domain\Event\InsertedFundsReturnedDueToInsufficientChange;
use App\VendingMachine\Domain\Event\ItemVended;
use App\VendingMachine\Domain\Event\VendingMachineEvent;
use App\VendingMachine\Domain\Event\VendingMachineProvisioned;
use App\VendingMachine\Domain\Event\VendingMachineServiced;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\ChangeDue;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendedItem;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;
use PHPUnit\Framework\TestCase;

final class VendingMachineEventTest extends TestCase
{
    public function test_provisioned_payload_carries_the_event_id_and_the_full_resulting_state(): void
    {
        $state = $this->state();
        $event = new VendingMachineProvisioned($state->id, $state);

        self::assertSame(
            ['eventId' => $event->eventId->toString(), 'state' => $this->expectedStateSnapshot()],
            $event->payload(),
        );
    }

    public function test_serviced_payload_carries_the_event_id_and_the_full_resulting_state(): void
    {
        $state = $this->state();
        $event = new VendingMachineServiced($state->id, $state);

        self::assertSame(
            ['eventId' => $event->eventId->toString(), 'state' => $this->expectedStateSnapshot()],
            $event->payload(),
        );
    }

    public function test_coin_inserted_payload_carries_the_event_id_the_denomination_and_state(): void
    {
        $state = $this->state();
        $event = new CoinInserted($state->id, $state, Denomination::of(Money::fromCents(25)));

        self::assertSame(
            [
                'eventId' => $event->eventId->toString(),
                'state' => $this->expectedStateSnapshot(),
                'denomination' => ['amountInCents' => 25],
            ],
            $event->payload(),
        );
    }

    public function test_item_vended_payload_carries_the_event_id_the_vended_item_and_state(): void
    {
        $state = $this->state();
        $vendedItem = new VendedItem('WATER', Money::fromCents(65), ChangeDue::fromCounts([25 => 1, 10 => 1]));
        $event = new ItemVended($state->id, $state, $vendedItem);

        self::assertSame(
            [
                'eventId' => $event->eventId->toString(),
                'state' => $this->expectedStateSnapshot(),
                'vendedItem' => [
                    'selectorCode' => 'WATER',
                    'priceInCents' => 65,
                    'change' => [25, 10],
                ],
            ],
            $event->payload(),
        );
    }

    public function test_inserted_funds_returned_payload_carries_the_event_id_the_returned_funds_and_state(): void
    {
        $state = $this->state();
        $event = new InsertedFundsReturned($state->id, $state, InsertedFunds::fromDenominations([25, 10]));

        self::assertSame(
            [
                'eventId' => $event->eventId->toString(),
                'state' => $this->expectedStateSnapshot(),
                'returnedFunds' => ['denominationsInCents' => [25, 10]],
            ],
            $event->payload(),
        );
    }

    public function test_insufficient_change_return_payload_carries_the_event_id_the_selector_code_the_returned_funds_and_state(): void
    {
        $state = $this->state();
        $event = new InsertedFundsReturnedDueToInsufficientChange(
            $state->id,
            $state,
            InsertedFunds::fromDenominations([25, 10]),
            'WATER',
        );

        self::assertSame(
            [
                'eventId' => $event->eventId->toString(),
                'state' => $this->expectedStateSnapshot(),
                'returnedFunds' => ['denominationsInCents' => [25, 10]],
                'selectorCode' => 'WATER',
            ],
            $event->payload(),
        );
    }

    public function test_every_event_reports_the_aggregate_id(): void
    {
        $state = $this->state();
        $id = $state->id->toString();

        self::assertSame($id, new VendingMachineProvisioned($state->id, $state)->aggregateId());
        self::assertSame($id, new VendingMachineServiced($state->id, $state)->aggregateId());
        self::assertSame(
            $id,
            new CoinInserted($state->id, $state, Denomination::of(Money::fromCents(25)))->aggregateId(),
        );
        self::assertSame(
            $id,
            new ItemVended(
                $state->id,
                $state,
                new VendedItem('WATER', Money::fromCents(65), ChangeDue::none()),
            )->aggregateId(),
        );
        self::assertSame($id, new InsertedFundsReturned($state->id, $state, InsertedFunds::none())->aggregateId());
        self::assertSame(
            $id,
            new InsertedFundsReturnedDueToInsufficientChange(
                $state->id,
                $state,
                InsertedFunds::none(),
                'WATER',
            )->aggregateId(),
        );
    }

    public function test_every_event_mints_a_fresh_uuidv7_event_id_when_recorded(): void
    {
        foreach ($this->events() as $event) {
            // Reading it back only succeeds when the id is a well-formed UUIDv7.
            self::assertSame($event->eventId(), EventId::fromString($event->eventId())->toString());
        }

        $first = new VendingMachineProvisioned($this->state()->id, $this->state());
        $second = new VendingMachineProvisioned($this->state()->id, $this->state());

        self::assertNotSame($first->eventId(), $second->eventId());
    }

    public function test_every_event_reports_the_version_the_mutation_left_the_state_at(): void
    {
        foreach ($this->events() as $event) {
            self::assertSame(4, $event->aggregateVersion());
        }
    }

    public function test_a_provisioned_event_rebuilds_from_its_payload(): void
    {
        $event = new VendingMachineProvisioned($this->state()->id, $this->state());

        $this->assertRoundTrip($event, VendingMachineProvisioned::fromPayload($event->payload()));
    }

    public function test_a_serviced_event_rebuilds_from_its_payload(): void
    {
        $event = new VendingMachineServiced($this->state()->id, $this->state());

        $this->assertRoundTrip($event, VendingMachineServiced::fromPayload($event->payload()));
    }

    public function test_a_coin_inserted_event_rebuilds_from_its_payload(): void
    {
        $event = new CoinInserted($this->state()->id, $this->state(), Denomination::of(Money::fromCents(25)));

        $this->assertRoundTrip($event, CoinInserted::fromPayload($event->payload()));
    }

    public function test_an_item_vended_event_rebuilds_from_its_payload(): void
    {
        $event = new ItemVended(
            $this->state()->id,
            $this->state(),
            new VendedItem('WATER', Money::fromCents(65), ChangeDue::fromCounts([25 => 1, 10 => 1])),
        );

        $this->assertRoundTrip($event, ItemVended::fromPayload($event->payload()));
    }

    public function test_an_inserted_funds_returned_event_rebuilds_from_its_payload(): void
    {
        $event = new InsertedFundsReturned(
            $this->state()->id,
            $this->state(),
            InsertedFunds::fromDenominations([25, 10]),
        );

        $this->assertRoundTrip($event, InsertedFundsReturned::fromPayload($event->payload()));
    }

    public function test_an_insufficient_change_return_event_rebuilds_from_its_payload(): void
    {
        $event = new InsertedFundsReturnedDueToInsufficientChange(
            $this->state()->id,
            $this->state(),
            InsertedFunds::fromDenominations([25, 10]),
            'WATER',
        );

        $this->assertRoundTrip($event, InsertedFundsReturnedDueToInsufficientChange::fromPayload($event->payload()));
    }

    private function assertRoundTrip(VendingMachineEvent $original, VendingMachineEvent $rebuilt): void
    {
        self::assertInstanceOf($original::class, $rebuilt);
        self::assertSame($original->eventId(), $rebuilt->eventId());
        self::assertSame($original->aggregateId(), $rebuilt->aggregateId());
        self::assertSame($original->aggregateVersion(), $rebuilt->aggregateVersion());
        self::assertSame($original->payload(), $rebuilt->payload());
    }

    /**
     * @return list<VendingMachineEvent>
     */
    private function events(): array
    {
        $state = $this->state();

        return [
            new VendingMachineProvisioned($state->id, $state),
            new VendingMachineServiced($state->id, $state),
            new CoinInserted($state->id, $state, Denomination::of(Money::fromCents(25))),
            new ItemVended(
                $state->id,
                $state,
                new VendedItem('WATER', Money::fromCents(65), ChangeDue::fromCounts([25 => 1, 10 => 1])),
            ),
            new InsertedFundsReturned($state->id, $state, InsertedFunds::fromDenominations([25, 10])),
            new InsertedFundsReturnedDueToInsufficientChange(
                $state->id,
                $state,
                InsertedFunds::fromDenominations([25, 10]),
                'WATER',
            ),
        ];
    }

    private function state(): VendingMachineState
    {
        return new VendingMachineState(
            VendingMachineId::fromString('0190f0c2-1a2b-7c3d-8e4f-0123456789ab'),
            AcceptedDenominations::fromCents([5, 10, 25, 100]),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 9)]),
            CashReserve::fromCounts([25 => 3, 10 => 1]),
            InsertedFunds::fromDenominations([25, 10]),
            4,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     acceptedDenominationsInCents: list<int>,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     insertedFunds: array{denominationsInCents: list<int>},
     *     version: int
     * }
     */
    private function expectedStateSnapshot(): array
    {
        return [
            'id' => '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
            'acceptedDenominationsInCents' => [5, 10, 25, 100],
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 9]],
            'cashReserve' => [
                ['denominationInCents' => 25, 'quantity' => 3],
                ['denominationInCents' => 10, 'quantity' => 1],
            ],
            'insertedFunds' => ['denominationsInCents' => [25, 10]],
            'version' => 4,
        ];
    }
}
