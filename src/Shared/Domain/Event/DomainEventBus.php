<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

/**
 * Publishes recorded domain events once a command has committed.
 *
 * Framework-free port: the write side hands events to this bus without knowing
 * whether they travel over Symfony Messenger, an in-memory dispatcher or
 * anything else.
 */
interface DomainEventBus
{
    public function publish(DomainEvent ...$events): void;
}
