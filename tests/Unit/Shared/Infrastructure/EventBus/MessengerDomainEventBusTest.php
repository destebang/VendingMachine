<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Infrastructure\EventBus\MessengerDomainEventBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerDomainEventBusTest extends TestCase
{
    public function test_it_dispatches_every_event_on_the_wrapped_bus(): void
    {
        $first = self::event();
        $second = self::event();

        /** @var list<object> $dispatched */
        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        new MessengerDomainEventBus($bus, new NullLogger())->publish($first, $second);

        self::assertSame([$first, $second], $dispatched);
    }

    public function test_a_failing_subscriber_is_swallowed_and_logged_and_does_not_stop_the_rest(): void
    {
        $first = self::event();
        $second = self::event();

        /** @var list<object> $dispatched */
        $dispatched = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            // The first event's dispatch (its subscriber) explodes.
            if (count($dispatched) === 1) {
                throw new RuntimeException('subscriber exploded');
            }

            return new Envelope($message);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('subscriber'),
                self::callback(
                    static fn(array $context): bool => (
                        ($context['event'] ?? null) === $first::class
                        && ($context['exception'] ?? null) instanceof RuntimeException
                    ),
                ),
            );

        new MessengerDomainEventBus($bus, $logger)->publish($first, $second);

        self::assertSame([$first, $second], $dispatched, 'A failing subscriber must not stop the remaining events.');
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
