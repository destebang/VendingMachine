<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\IntegrationEventTranslator;

use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use App\VendingMachine\Application\IntegrationEvent\ItemVendedV1;
use App\VendingMachine\Domain\Event\ItemVended;
use DateTimeImmutable;

/**
 * Maps the outbox's stored events to integration events: the translation
 * between the context's internal facts and its published language. This is
 * the only code allowed to see both sides of that boundary.
 *
 * Only `ItemVended` is published for now; every other event type is dropped
 * (the `default` arm returns `null`) — the published language exposes only
 * what other contexts may know. The envelope id and correlation id are the
 * domain event's id, so a published message always correlates back to the
 * recorded fact that caused it.
 */
final class VendingMachineIntegrationEventTranslator
{
    /**
     * @param array<string, mixed> $payload the payload the outbox stored for the event
     */
    public function translate(string $type, array $payload, DateTimeImmutable $occurredAt): ?IntegrationEvent
    {
        return match ($type) {
            ItemVended::class => self::itemVendedV1($payload, $occurredAt),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function itemVendedV1(array $payload, DateTimeImmutable $occurredAt): ItemVendedV1
    {
        // The outbox stores exactly what `ItemVended::payload()` produced; the
        // assertion is checked against `fromPayload`'s contract by the
        // analyzer, so a payload shape change fails loudly instead of lying.
        /** @var array{eventId: string, state: array{id: string, acceptedDenominationsInCents: list<int>, stock: list<array{selectorCode: string, priceInCents: int, stock: int}>, cashReserve: list<array{denominationInCents: int, quantity: int}>, insertedFunds: array{denominationsInCents: list<int>}, version: int}, vendedItem: array{selectorCode: string, priceInCents: int, change: list<int>}} $payload */
        $event = ItemVended::fromPayload($payload);

        return new ItemVendedV1(
            id: $event->eventId(),
            vendingMachineId: $event->aggregateId(),
            aggregateVersion: $event->aggregateVersion(),
            occurredAt: $occurredAt,
            correlationId: $event->eventId(),
            selectorCode: $event->vendedItem->selectorCode,
            priceInCents: $event->vendedItem->price->amountInCents(),
            changeInCents: $event->vendedItem->changeDue->denominationsInCents(),
        );
    }
}
