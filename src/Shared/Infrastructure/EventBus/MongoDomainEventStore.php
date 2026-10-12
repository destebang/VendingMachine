<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEvent;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/**
 * Append-only traceability store for every published domain event.
 *
 * Generic across aggregates: it derives `aggregateType` and `eventType` from the
 * event's FQCN (e.g. `App\VendingMachine\Domain\Event\CoinInserted` →
 * `VendingMachine` / `CoinInserted`) and stamps `occurredAt` at write time, so
 * the events themselves stay timestamp-free. One flat document per event is
 * appended to the `domain_events` collection.
 */
final class MongoDomainEventStore
{
    public function __construct(
        private readonly Collection $collection,
    ) {}

    public function __invoke(DomainEvent $event): void
    {
        $this->collection->insertOne([
            'aggregateType' => self::aggregateType($event),
            'aggregateId' => $event->aggregateId(),
            'eventType' => self::eventType($event),
            'payload' => $event->payload(),
            'occurredAt' => new UTCDateTime(),
        ]);
    }

    /**
     * The aggregate an event belongs to, read from its namespace: the segment
     * right after `App\`.
     */
    private static function aggregateType(DomainEvent $event): string
    {
        return explode('\\', $event::class)[1] ?? '';
    }

    /**
     * The event's own class name, without its namespace.
     */
    private static function eventType(DomainEvent $event): string
    {
        $parts = explode('\\', $event::class);

        return $parts[count($parts) - 1];
    }
}
