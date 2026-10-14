<?php

declare(strict_types=1);

namespace App\Kernel\Bus;

use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Kernel adapter that backs the framework-free `QueryBus` port with a Symfony
 * Messenger bus. This is the only place the read-side port touches the
 * framework; the driving adapters just see `QueryBus`.
 */
final class MessengerQueryBus implements QueryBus
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function ask(object $query): mixed
    {
        return $this->handle($query);
    }
}
