<?php

declare(strict_types=1);

namespace App\Shared\Application\IntegrationEvent;

use DateTimeImmutable;

/**
 * Base for every integration event: a message published to other contexts.
 *
 * Integration events are the context's *published language* — deliberately
 * separate classes from domain events. A domain event is an internal fact,
 * free to carry rich domain objects and to change with the model; an
 * integration event is a public contract, versioned (`...v1`), carrying a
 * small primitive payload that other contexts can depend on without seeing
 * the domain. The translator in the owning context maps one to the other.
 *
 * The envelope fields here are the stable envelope every integration message
 * travels in (see `JsonEnvelopeSerializer`); concrete events only add their
 * `data()` payload. The `id` is the identity of the domain event that caused
 * the message, so consumers can deduplicate the at-least-once delivery.
 */
abstract readonly class IntegrationEvent
{
    public function __construct(
        /**
         * The message identity — the causing domain event's id, so a consumer
         * seeing the same message twice can drop the duplicate.
         */
        public string $id,
        /**
         * The aggregate the event belongs to.
         */
        public string $aggregateId,
        /**
         * The aggregate's version once the recorded mutation was applied, so
         * a consumer can order the messages of one aggregate.
         */
        public int $aggregateVersion,
        /**
         * When the recorded fact was persisted, stamped by the outbox at
         * write time.
         */
        public DateTimeImmutable $occurredAt,
        /**
         * Correlates the message back to the cause of the work. For now this
         * is the causing domain event's id.
         */
        public string $correlationId,
    ) {}

    /**
     * The message type, e.g. `vending_machine.item_vended.v1`. Used as
     * the routing key on the integration exchange.
     */
    abstract public function type(): string;

    /**
     * The context that publishes the event — the "source" of the published
     * language.
     */
    abstract public function source(): string;

    /**
     * The event's own data: a small payload of primitives (scalars and lists
     * of scalars), never domain objects.
     *
     * @return array<string, mixed>
     */
    abstract public function data(): array;

    /**
     * Rebuilds the event from a decoded envelope, so a consumer on the other
     * side of the bus gets a typed message again.
     *
     * @param array<string, mixed> $data
     */
    abstract public static function fromEnvelope(
        string $id,
        string $aggregateId,
        int $aggregateVersion,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        array $data,
    ): static;
}
