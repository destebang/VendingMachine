<?php

declare(strict_types=1);

namespace App\Kernel\IntegrationEvent;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Command\SetupTransportsCommand;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;

/**
 * Builds Symfony Messenger's own console commands by hand — the wiring
 * `symfony/framework-bundle` would otherwise generate. Both commands work
 * over the single `integration_events` AMQP transport and the
 * `integration.bus` the consumed messages are dispatched to.
 */
final class MessengerCommandsFactory
{
    public const string TRANSPORT_NAME = 'integration_events';

    public static function createConsumeMessagesCommand(
        MessageBusInterface $integrationBus,
        AmqpTransport $transport,
    ): ConsumeMessagesCommand {
        return new ConsumeMessagesCommand(
            new RoutableMessageBus(new ServiceLocator([
                'integration.bus' => static fn(): MessageBusInterface => $integrationBus,
            ]), $integrationBus),
            new ServiceLocator([self::TRANSPORT_NAME => static fn(): AmqpTransport => $transport]),
            new EventDispatcher(),
            null,
            [self::TRANSPORT_NAME],
            null,
            ['integration.bus'],
        );
    }

    public static function createSetupTransportsCommand(AmqpTransport $transport): SetupTransportsCommand
    {
        return new SetupTransportsCommand(new ServiceLocator([
            self::TRANSPORT_NAME => static fn(): AmqpTransport => $transport,
        ]), [self::TRANSPORT_NAME]);
    }
}
