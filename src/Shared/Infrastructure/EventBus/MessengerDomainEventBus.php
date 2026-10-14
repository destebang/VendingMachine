<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventBus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Publishes domain events onto a dedicated Symfony Messenger bus, in-process
 * and synchronously.
 *
 * Each event is dispatched on its own so one failing subscriber cannot stop
 * the rest: a subscriber that throws is swallowed and logged, never failing
 * the command that produced the event — its write model is already durably
 * saved by the time events are published.
 */
final class MessengerDomainEventBus implements DomainEventBus
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            try {
                $this->bus->dispatch($event);
            } catch (Throwable $exception) {
                $this->logger->error('A domain event subscriber failed.', [
                    'event' => $event::class,
                    'exception' => $exception,
                ]);
            }
        }
    }
}
