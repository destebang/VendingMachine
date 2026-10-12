<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Event;

use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\InsertedFundsReturned;
use App\VendingMachine\Domain\Event\InsertedFundsReturnedDueToInsufficientChange;
use App\VendingMachine\Domain\Event\ItemVended;
use App\VendingMachine\Domain\Event\VendingMachineProvisioned;
use App\VendingMachine\Domain\Event\VendingMachineServiced;
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
    public function test_provisioned_payload_carries_the_full_resulting_state(): void
    {
        $state = $this->state();
        $event = new VendingMachineProvisioned($state->id, $state);

        self::assertSame(['state' => $this->expectedStateSnapshot()], $event->payload());
    }

    public function test_serviced_payload_carries_the_full_resulting_state(): void
    {
        $state = $this->state();
        $event = new VendingMachineServiced($state->id, $state);

        self::assertSame(['state' => $this->expectedStateSnapshot()], $event->payload());
    }

    public function test_coin_inserted_payload_carries_the_denomination_and_state(): void
    {
        $state = $this->state();
        $event = new CoinInserted($state->id, $state, Denomination::of(Money::fromCents(25)));

        self::assertSame(
            [
                'state' => $this->expectedStateSnapshot(),
                'denomination' => ['amountInCents' => 25],
            ],
            $event->payload(),
        );
    }

    public function test_item_vended_payload_carries_the_vended_item_and_state(): void
    {
        $state = $this->state();
        $vendedItem = new VendedItem('WATER', Money::fromCents(65), ChangeDue::fromCounts([25 => 1, 10 => 1]));
        $event = new ItemVended($state->id, $state, $vendedItem);

        self::assertSame(
            [
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

    public function test_inserted_funds_returned_payload_carries_the_returned_funds_and_state(): void
    {
        $state = $this->state();
        $event = new InsertedFundsReturned($state->id, $state, InsertedFunds::fromDenominations([25, 10]));

        self::assertSame(
            [
                'state' => $this->expectedStateSnapshot(),
                'returnedFunds' => ['denominationsInCents' => [25, 10]],
            ],
            $event->payload(),
        );
    }

    public function test_insufficient_change_return_payload_carries_the_returned_funds_and_state(): void
    {
        $state = $this->state();
        $event = new InsertedFundsReturnedDueToInsufficientChange(
            $state->id,
            $state,
            InsertedFunds::fromDenominations([25, 10]),
        );

        self::assertSame(
            [
                'state' => $this->expectedStateSnapshot(),
                'returnedFunds' => ['denominationsInCents' => [25, 10]],
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
            new InsertedFundsReturnedDueToInsufficientChange($state->id, $state, InsertedFunds::none())->aggregateId(),
        );
    }

    private function state(): VendingMachineState
    {
        return new VendingMachineState(
            VendingMachineId::fromString('0190f0c2-1a2b-7c3d-8e4f-0123456789ab'),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 9)]),
            CashReserve::fromCounts([25 => 3, 10 => 1]),
            InsertedFunds::fromDenominations([25, 10]),
            4,
        );
    }

    /**
     * @return array{
     *     id: string,
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
