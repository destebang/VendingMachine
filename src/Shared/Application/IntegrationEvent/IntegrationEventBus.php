<?php

declare(strict_types=1);

namespace App\Shared\Application\IntegrationEvent;

/**
 * Publishes integration events to other contexts.
 *
 * Framework-free port: application code hands integration events to this bus
 * without knowing whether they travel over AMQP, an in-memory dispatcher or
 * anything else. Unlike the synchronous `DomainEventBus`, delivery here is
 * asynchronous and at-least-once.
 */
interface IntegrationEventBus
{
    public function publish(IntegrationEvent ...$events): void;
}
