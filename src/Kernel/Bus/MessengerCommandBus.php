<?php

declare(strict_types=1);

namespace App\Kernel\Bus;

use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Kernel adapter that backs the framework-free `CommandBus` port with a Symfony
 * Messenger bus. This is the only place the write-side port touches the
 * framework; it unwraps Messenger's `HandlerFailedException` so callers see the
 * domain exception a handler actually threw.
 */
final class MessengerCommandBus implements CommandBus
{
    use HandleTrait {
        handle as private dispatch;
    }

    public function __construct(MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    public function handle(object $command): mixed
    {
        try {
            return $this->dispatch($command);
        } catch (HandlerFailedException $exception) {
            throw $exception->getPrevious() ?? $exception;
        }
    }
}
