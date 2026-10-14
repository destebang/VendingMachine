<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * The minimal contract every domain event exposes to infrastructure.
 *
 * A domain event is a fully-formed, immutable fact about something that
 * happened on an aggregate. It stays framework-free and timestamp-free: an
 * event knows which aggregate it belongs to, its own identity, where the
 * aggregate's version stood once it was applied, and what it carries — nothing
 * about how or when it is stored.
 *
 * Deliberately narrow. The generic event store derives `aggregateType` and
 * `eventType` from the event's FQCN and stamps `occurredAt` at write time, so
 * an event does not describe its own persistence. `eventId()` and
 * `aggregateVersion()` are the two pieces infrastructure cannot derive on its
 * own: they let a store or bus deduplicate events and order them within one
 * aggregate.
 */
interface DomainEvent
{
    /**
     * The identity of the aggregate that recorded the event.
     */
    public function aggregateId(): string;

    /**
     * The event's own identity, unique across every event ever recorded. The
     * one handle by which the same fact can be recognised when it is seen
     * again — a store row, an outbox entry, a delivered message — instead of
     * being processed twice.
     */
    public function eventId(): string;

    /**
     * The aggregate's version once the mutation this event records was
     * applied. Monotonic per aggregate, so the events of one aggregate can be
     * ordered and a stale one recognised.
     */
    public function aggregateVersion(): int;

    /**
     * The event's data, ready to be persisted, as a plain array.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
