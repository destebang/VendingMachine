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
 * Base for integration suites that drive use cases the way the driving
 * adapters do: dispatched through the real `command.bus` from the hand-built
 * container, middleware included. That exercises the whole chain — handler,
 * write-model save, event collection and the synchronous publish that
 * projects the read model — with no in-memory doubles.
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Dispatches a command through the container's real command bus and
     * returns the handler's result.
     */
    protected function dispatch(ContainerBuilder $container, object $command): mixed
    {
        $bus = $container->get('command.bus');
        assert($bus instanceof MessageBusInterface, description: 'The command.bus service must be a Messenger bus.');

        return $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
    }

    /**
     * Dispatches a command expected to fail and returns the exception the
     * handler threw, unwrapped from Messenger's `HandlerFailedException` the
     * same way `MessengerCommandBus` unwraps it for the driving adapters.
     */
    protected function dispatchExpectingFailure(ContainerBuilder $container, object $command): Throwable
    {
        try {
            $this->dispatch($container, $command);
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious() ?? $exception;
        }

        self::fail('Expected the command to throw, but it was handled successfully.');
    }
}
