<?php

declare(strict_types=1);

namespace App\Kernel\IntegrationEvent;

use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use App\Shared\Application\IntegrationEvent\IntegrationEventBus;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Kernel adapter that backs the framework-free `IntegrationEventBus` port
 * with Symfony Messenger's AMQP transport: messages are published to the
 * durable `vending_machine.integration` topic exchange, with the event's `type` as
 * the routing key, and persisted (`delivery_mode` 2 is the transport's
 * default). The transport waits for the broker's publisher confirm, so
 * `publish()` only returns once the message is durably held — and throws
 * otherwise, letting the outbox relay retry on its next poll.
 */
final class MessengerIntegrationEventBus implements IntegrationEventBus
{
    public function __construct(
        private readonly TransportInterface $transport,
    ) {}

    public function publish(IntegrationEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->transport->send(new Envelope($event)->with(new AmqpStamp(routingKey: $event->type())));
        }
    }
}
