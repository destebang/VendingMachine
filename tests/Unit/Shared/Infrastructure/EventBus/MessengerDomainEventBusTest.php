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
        $first = $this->event('first');
        $second = $this->event('second');

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

    public function test_a_failing_listener_is_swallowed_and_logged(): void
    {
        $event = $this->event('boom');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new RuntimeException('listener exploded'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::anything(),
                self::callback(static fn(array $context): bool => ($context['event'] ?? null) === $event::class),
            );

        new MessengerDomainEventBus($bus, $logger)->publish($event);
    }

    private function event(string $id): DomainEvent
    {
        return new class($id) implements DomainEvent {
            public function __construct(
                private readonly string $id,
            ) {}

            public function aggregateId(): string
            {
                return $this->id;
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
