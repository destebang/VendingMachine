<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEventBus;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Publishes the domain events a command recorded, once the command is done.
 *
 * Sits outside `handle_message_middleware` on the command bus and wraps the
 * inner handler in `try/finally`, so events are released and published for
 * every outcome — including a rejection that mutated state (the exact-change
 * refund) and a command whose handler throws after committing. That makes the
 * rule structural rather than something each handler has to remember.
 */
final class ReleaseDomainEventsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DomainEventCollector $collector,
        private readonly DomainEventBus $eventBus,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $events = $this->collector->release();

            if ($events !== []) {
                $this->eventBus->publish(...$events);
            }
        }
    }
}
