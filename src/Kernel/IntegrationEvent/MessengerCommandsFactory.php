<?php

declare(strict_types=1);

namespace App\Kernel\IntegrationEvent;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Command\SetupTransportsCommand;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Builds Symfony Messenger's own console commands by hand — the wiring
 * `symfony/framework-bundle` would otherwise generate.
 *
 * The consumer worker runs over the Sales transport (`sales.item_vended`),
 * dispatching decoded messages to the `integration.bus` the subscriber is
 * autoconfigured onto. Its event dispatcher carries the two listeners the
 * framework would normally register: one retries a failed message with a
 * backoff, re-sending it to the same transport through the AMQP delay
 * exchange; the other, once retries are exhausted, dead-letters the message to
 * the failure transport (`sales.item_vended.dlq`).
 */
final class MessengerCommandsFactory
{
    /** The producer's transport name; its queue is not consumed here. */
    public const string TRANSPORT_NAME = 'integration_events';

    public const string SALES_TRANSPORT_NAME = 'sales_integration_events';

    public const string SALES_FAILURE_TRANSPORT_NAME = 'sales_integration_events_failed';

    public const string BUS_NAME = 'integration.bus';

    public static function createConsumeMessagesCommand(
        MessageBusInterface $integrationBus,
        AmqpTransport $salesTransport,
        AmqpTransport $salesFailureTransport,
        ?RetryStrategyInterface $retryStrategy = null,
    ): ConsumeMessagesCommand {
        return new ConsumeMessagesCommand(
            new RoutableMessageBus(new ServiceLocator([
                self::BUS_NAME => static fn(): MessageBusInterface => $integrationBus,
            ]), $integrationBus),
            new ServiceLocator([
                self::SALES_TRANSPORT_NAME => static fn(): AmqpTransport => $salesTransport,
            ]),
            self::workerEventDispatcher(
                $salesTransport,
                $salesFailureTransport,
                $retryStrategy ?? self::defaultRetryStrategy(),
            ),
            null,
            [self::SALES_TRANSPORT_NAME],
            null,
            [self::BUS_NAME],
        );
    }

    /**
     * @param array<string, AmqpTransport> $transports
     */
    public static function createSetupTransportsCommand(array $transports): SetupTransportsCommand
    {
        $locator = [];

        foreach ($transports as $name => $transport) {
            $locator[$name] = static fn(): AmqpTransport => $transport;
        }

        return new SetupTransportsCommand(new ServiceLocator($locator), array_keys($locator));
    }

    /**
     * The worker's event dispatcher: retry with a backoff, then dead-letter.
     * Both listeners are keyed by the transport name the worker reports, which
     * is the name the transport is registered under.
     */
    private static function workerEventDispatcher(
        AmqpTransport $transport,
        AmqpTransport $failureTransport,
        RetryStrategyInterface $retryStrategy,
    ): EventDispatcher {
        $dispatcher = new EventDispatcher();

        $dispatcher->addSubscriber(
            new SendFailedMessageForRetryListener(
                new ServiceLocator([
                    self::SALES_TRANSPORT_NAME => static fn(): SenderInterface => $transport,
                ]),
                new ServiceLocator([
                    self::SALES_TRANSPORT_NAME => static fn(): RetryStrategyInterface => $retryStrategy,
                ]),
            ),
        );

        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            new ServiceLocator([
                self::SALES_TRANSPORT_NAME => static fn(): SenderInterface => $failureTransport,
            ]),
            null,
            [self::SALES_TRANSPORT_NAME => self::SALES_FAILURE_TRANSPORT_NAME],
        ));

        return $dispatcher;
    }

    /**
     * Three retries with an exponential backoff (1s, 2s, 4s, capped at 30s)
     * before the message is dead-lettered.
     */
    private static function defaultRetryStrategy(): RetryStrategyInterface
    {
        return new MultiplierRetryStrategy(
            maxRetries: 3,
            delayMilliseconds: 1000,
            multiplier: 2.0,
            maxDelayMilliseconds: 30_000,
        );
    }
}
