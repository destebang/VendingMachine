<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventBus;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\DomainEventBus;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\Shared\Infrastructure\EventBus\PublishDomainEventsMiddleware;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class PublishDomainEventsMiddlewareTest extends TestCase
{
    public function test_it_publishes_the_collected_events_once_the_command_succeeds(): void
    {
        $collector = new DomainEventCollector();
        $eventBus = $this->createMock(DomainEventBus::class);

        $event = self::event();

        // The inner handler records an event while handling the command.
        $middleware = new PublishDomainEventsMiddleware($collector, $eventBus);
        $stack = $this->stackReturning(static function () use ($collector, $event): Envelope {
            $collector->collect($event);

            return new Envelope(new \stdClass());
        });

        $eventBus->expects(self::once())->method('publish')->with(self::identicalTo($event));

        $middleware->handle(new Envelope(new \stdClass()), $stack);
    }

    public function test_it_still_publishes_the_collected_events_when_the_handler_throws(): void
    {
        $collector = new DomainEventCollector();
        $eventBus = $this->createMock(DomainEventBus::class);

        $event = self::event();

        // The handler records an event and then rejects: the event must be
        // published anyway, and the rejection must propagate untouched.
        $middleware = new PublishDomainEventsMiddleware($collector, $eventBus);
        $stack = $this->stackReturning(static function () use ($collector, $event): Envelope {
            $collector->collect($event);

            throw new RuntimeException('handler rejected after committing');
        });

        $eventBus->expects(self::once())->method('publish')->with(self::identicalTo($event));

        try {
            $middleware->handle(new Envelope(new \stdClass()), $stack);
            self::fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('handler rejected after committing', $exception->getMessage());
        }
    }

    public function test_it_publishes_nothing_when_the_command_recorded_nothing(): void
    {
        $collector = new DomainEventCollector();
        $eventBus = $this->createMock(DomainEventBus::class);

        $middleware = new PublishDomainEventsMiddleware($collector, $eventBus);
        $stack = $this->stackReturning(static fn(): Envelope => new Envelope(new \stdClass()));

        $eventBus->expects(self::never())->method('publish');

        $middleware->handle(new Envelope(new \stdClass()), $stack);
    }

    public function test_a_second_command_sees_no_events_from_the_first(): void
    {
        $collector = new DomainEventCollector();
        $eventBus = $this->createMock(DomainEventBus::class);

        $middleware = new PublishDomainEventsMiddleware($collector, $eventBus);

        // First command records an event; the second records nothing.
        $first = $this->stackReturning(static function () use ($collector): Envelope {
            $collector->collect(self::event());

            return new Envelope(new \stdClass());
        });

        // The event is published exactly once (for the first command), so the
        // collector is provably drained and the second command publishes
        // nothing.
        $eventBus->expects(self::once())->method('publish');

        $middleware->handle(new Envelope(new \stdClass()), $first);
        $middleware->handle(
            new Envelope(new \stdClass()),
            $this->stackReturning(static fn(): Envelope => new Envelope(new \stdClass())),
        );
    }

    /**
     * @param Closure(Envelope): Envelope $handler
     */
    private function stackReturning(Closure $handler): StackInterface
    {
        return new class($handler) implements StackInterface {
            /**
             * @param Closure(Envelope): Envelope $handler
             */
            public function __construct(
                private readonly Closure $handler,
            ) {}

            public function next(): MiddlewareInterface
            {
                return new class($this->handler) implements MiddlewareInterface {
                    /**
                     * @param Closure(Envelope): Envelope $handler
                     */
                    public function __construct(
                        private readonly Closure $handler,
                    ) {}

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        return ($this->handler)($envelope);
                    }
                };
            }
        };
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
