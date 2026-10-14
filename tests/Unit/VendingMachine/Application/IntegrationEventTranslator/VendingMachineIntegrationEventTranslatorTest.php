<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Application\IntegrationEventTranslator;

use App\VendingMachine\Application\IntegrationEvent\ItemVendedV1;
use App\VendingMachine\Application\IntegrationEventTranslator\VendingMachineIntegrationEventTranslator;
use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\ItemVended;
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
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class VendingMachineIntegrationEventTranslatorTest extends TestCase
{
    public function test_item_vended_is_translated_to_the_published_v1_message(): void
    {
        $state = $this->state();
        $event = new ItemVended(
            $state->id,
            $state,
            new VendedItem('WATER', Money::fromCents(65), ChangeDue::fromCounts([25 => 1, 10 => 1])),
        );
        $occurredAt = new DateTimeImmutable('2026-10-14T10:30:00+00:00');

        $translated = new VendingMachineIntegrationEventTranslator()->translate(
            ItemVended::class,
            $event->payload(),
            $occurredAt,
        );

        self::assertInstanceOf(ItemVendedV1::class, $translated);
        // The envelope id is the domain event's id — the handle consumers
        // deduplicate on — and it doubles as the correlation id.
        self::assertSame($event->eventId(), $translated->id);
        self::assertSame($event->eventId(), $translated->correlationId);
        self::assertSame($state->id->toString(), $translated->aggregateId);
        self::assertSame(7, $translated->aggregateVersion);
        self::assertSame($occurredAt, $translated->occurredAt);
        self::assertSame('vending_machine.item_vended.v1', $translated->type());
        self::assertSame('vending-machine', $translated->source());
        self::assertSame(
            [
                'vendingMachineId' => $state->id->toString(),
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'changeInCents' => [25, 10],
            ],
            $translated->data(),
        );
    }

    public function test_events_outside_the_published_language_are_dropped(): void
    {
        $state = $this->state();
        $event = new CoinInserted($state->id, $state, Denomination::of(Money::fromCents(25)));

        self::assertNull(new VendingMachineIntegrationEventTranslator()->translate(
            CoinInserted::class,
            $event->payload(),
            new DateTimeImmutable(),
        ));
    }

    private function state(): VendingMachineState
    {
        return new VendingMachineState(
            VendingMachineId::fromString('0190f0c2-1a2b-7c3d-8e4f-0123456789ab'),
            AcceptedDenominations::fromCents([5, 10, 25, 100]),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 9)]),
            CashReserve::fromCounts([25 => 3, 10 => 1]),
            InsertedFunds::none(),
            7,
        );
    }
}
