<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * The minimal contract every domain event exposes to infrastructure.
 *
 * A domain event is a fully-formed, immutable fact about something that
 * happened on an aggregate. It stays framework-free and timestamp-free: an
 * event knows which aggregate it belongs to and what it carries, nothing about
 * how or when it is stored.
 *
 * Deliberately narrow. The generic event store derives `aggregateType` and
 * `eventType` from the event's FQCN and stamps `occurredAt` at write time, so
 * an event does not describe its own persistence.
 */
interface DomainEvent
{
    /**
     * The identity of the aggregate that recorded the event.
     */
    public function aggregateId(): string;

    /**
     * The event's data, ready to be persisted, as a plain array.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
