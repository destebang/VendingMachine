<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Base for integration tests that drive use cases through the real command bus,
 * so the release middleware, the event bus, the MongoDB store and the read-model
 * projection all run exactly as they do in production.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Dispatches a command and returns its handler's result.
     */
    protected function dispatch(ContainerBuilder $container, object $command): mixed
    {
        $bus = $container->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $stamp = $bus->dispatch($command)->last(HandledStamp::class);

        return $stamp instanceof HandledStamp ? $stamp->getResult() : null;
    }

    /**
     * Dispatches a command that is expected to be rejected and returns the
     * domain exception, unwrapped from Messenger's `HandlerFailedException`.
     */
    protected function dispatchExpectingFailure(ContainerBuilder $container, object $command): Throwable
    {
        try {
            $this->dispatch($container, $command);
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious() ?? $exception;
        }

        self::fail('Expected the command to be rejected.');
    }
}
