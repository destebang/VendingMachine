<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use PHPUnit\Framework\TestCase;

final class DomainEventCollectorTest extends TestCase
{
    public function test_release_hands_out_every_collected_event_exactly_once(): void
    {
        $collector = new DomainEventCollector();

        self::assertSame([], $collector->release());

        $first = self::event();
        $second = self::event();

        $collector->collect($first);
        $collector->collect($second);

        self::assertSame([$first, $second], $collector->release());
        self::assertSame([], $collector->release(), 'A released event must never be handed out twice.');
    }

    private static function event(): DomainEvent
    {
        return new class implements DomainEvent {
            public function aggregateId(): string
            {
                return 'aggregate-id';
            }

            public function eventId(): string
            {
                return 'event-id';
            }

            public function aggregateVersion(): int
            {
                return 1;
            }

            /**
             * @return array<string, mixed>
             */
            public function payload(): array
            {
                return [];
            }
        };
    }
}
