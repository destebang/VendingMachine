<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use PHPUnit\Framework\TestCase;

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
            7,
            $handlerDescriptors,
            'MessengerPass must build one handler descriptor per autoconfigured handler.',
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
}
