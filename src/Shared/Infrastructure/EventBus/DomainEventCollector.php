<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEvent;

/**
 * Per-request, in-memory bookkeeping for domain events recorded during a
 * command.
 *
 * The write-side repository hands every event it drains from an aggregate to
 * the collector once the write has committed; the command-bus middleware
 * drains the collector when the command is done and publishes everything at
 * once. Pure bookkeeping — deliberately a concrete class with no interface.
 */
final class DomainEventCollector
{
    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    public function collect(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /**
     * Returns every collected event and clears the collector, so each event is
     * published exactly once and never leaks into the next command.
     *
     * @return list<DomainEvent>
     */
    public function release(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
