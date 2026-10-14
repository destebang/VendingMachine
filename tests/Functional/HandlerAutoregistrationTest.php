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
}
