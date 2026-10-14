<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\VendingMachine\Application\ReadModel\VendingMachineReadModelProjector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Reference;

final class HandlerAutoregistrationTest extends TestCase
{
    public function test_handlers_are_autoconfigured_onto_the_command_bus(): void
    {
        $container = ContainerFactory::create();

        self::assertFalse(
            $container->hasDefinition('command_bus.handlers_locator'),
            'The hand-written handlers locator must be gone.',
        );
        self::assertTrue(
            $container->hasDefinition('command.bus.middleware.handle_message'),
            'The command bus middleware must be the name Symfony MessengerPass looks for.',
        );

        $handlerDescriptors = array_filter(
            array_keys($container->getDefinitions()),
            static fn(string $id): bool => str_starts_with($id, '.messenger.handler_descriptor.'),
        );

        self::assertCount(
            16,
            $handlerDescriptors,
            'MessengerPass must build one handler descriptor per autoconfigured handler: '
            . '9 command/query handlers, the 6 event-subscriber methods of the read-model projector, '
            . 'and the Sales integration-event subscriber.',
        );
    }

    public function test_the_read_side_has_its_own_bus(): void
    {
        $container = ContainerFactory::create();

        self::assertTrue(
            $container->hasDefinition('command.bus.middleware.handle_message'),
            'The command bus must keep its own middleware.',
        );
        self::assertTrue(
            $container->hasDefinition('query.bus.middleware.handle_message'),
            'The query bus must have its own middleware.',
        );
        self::assertTrue(
            $container->hasDefinition('App\\Kernel\\Bus\\MessengerQueryBus'),
            'The QueryBus port must be backed by the Kernel adapter.',
        );
    }

    public function test_the_write_side_is_backed_by_the_command_bus_port(): void
    {
        $container = ContainerFactory::create();

        self::assertTrue(
            $container->hasDefinition('App\\Kernel\\Bus\\MessengerCommandBus'),
            'The CommandBus port must be backed by the Kernel adapter.',
        );
        self::assertTrue(
            $container->hasDefinition('command.bus.middleware.handle_message'),
            'The command bus middleware must stay in place.',
        );
    }

    public function test_subscribers_are_autoconfigured_onto_the_domain_event_bus(): void
    {
        $container = ContainerFactory::create();

        self::assertTrue(
            $container->hasDefinition('domain_event.bus.middleware.handle_message'),
            'The domain event bus must have its own middleware.',
        );
        self::assertFalse(
            $container->hasDefinition('domain_event_bus.handlers_locator'),
            'No hand-written event handlers locator may exist.',
        );

        /** @var list<array<string, string>> $tags */
        $tags = $container->getDefinition(VendingMachineReadModelProjector::class)->getTag('messenger.message_handler');

        self::assertCount(
            6,
            $tags,
            'The projector must be tagged once per event method by the #[DomainEventSubscriber] attribute.',
        );

        foreach ($tags as $tag) {
            self::assertSame('domain_event.bus', $tag['bus'] ?? null);
            self::assertArrayHasKey('method', $tag);
        }
    }

    public function test_the_command_bus_publishes_domain_events_outside_handle_message(): void
    {
        $container = ContainerFactory::create();

        // MessengerPass consumes the `command.bus.middleware` parameter and
        // bakes the middleware list into the bus definition itself.
        /** @var IteratorArgument $middleware */
        $middleware = $container->getDefinition('command.bus')->getArgument(0);
        assert($middleware instanceof IteratorArgument, description: 'The command bus must hold its middleware list.');

        self::assertSame(
            [
                'App\\Shared\\Infrastructure\\EventBus\\PublishDomainEventsMiddleware',
                'command.bus.middleware.handle_message',
            ],
            array_map(static fn(Reference $reference): string => (string) $reference, $middleware->getValues()),
            'The publish middleware must wrap handle_message, so events are published for every outcome.',
        );
    }
}
