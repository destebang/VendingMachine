<?php

declare(strict_types=1);

namespace App\Kernel\IntegrationEvent;

use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransport;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;

/**
 * Builds the AMQP transport of the integration bus by hand, since there is
 * no framework bundle to do it via configuration. Connection details come
 * from the `MESSENGER_TRANSPORT_DSN` environment variable; the exchange,
 * queue and publisher-confirm settings are the context's contract and stay
 * in code.
 *
 * The exchange is the durable topic exchange `vending_machine.integration`, the
 * published language's well-known address: publishers route by event type,
 * and each consuming context binds its own queue with the routing keys it
 * cares about. The context's own queue (`vending-machine.integration`) is
 * bound to every vending-machine message, ready for the subscriber slice.
 * `confirm_timeout` enables publisher confirms, so sending only returns once
 * the broker durably holds the message.
 */
final class AmqpTransportFactory
{
    private const string EXCHANGE = 'vending_machine.integration';

    private const string QUEUE = 'vending-machine.integration';

    private const string DEFAULT_DSN = 'amqp://guest:guest@rabbitmq:5672/%2f';

    public static function create(JsonEnvelopeSerializer $serializer): AmqpTransport
    {
        return self::transport($serializer, self::QUEUE, ['vending_machine.#']);
    }

    /**
     * The same exchange, seen from a queue of the caller's choosing — how a
     * consuming context (or a test) listens to a selection of routing keys
     * without touching the context's own queue.
     *
     * @param list<string> $bindingKeys
     */
    public static function createForQueue(
        JsonEnvelopeSerializer $serializer,
        string $queueName,
        array $bindingKeys,
    ): AmqpTransport {
        return self::transport($serializer, $queueName, $bindingKeys);
    }

    /**
     * @param list<string> $bindingKeys
     */
    private static function transport(
        JsonEnvelopeSerializer $serializer,
        string $queueName,
        array $bindingKeys,
    ): AmqpTransport {
        $connection = Connection::fromDsn($_ENV['MESSENGER_TRANSPORT_DSN'] ?? self::DEFAULT_DSN, [
            // Publisher confirms: send() waits for the broker's ack and
            // throws on a nack or timeout, so a message is either durably
            // held or the caller knows it is not.
            'confirm_timeout' => 5,
            'exchange' => [
                'name' => self::EXCHANGE,
                'type' => \AMQP_EX_TYPE_TOPIC,
                'flags' => \AMQP_DURABLE,
            ],
            'queues' => [
                $queueName => [
                    'flags' => \AMQP_DURABLE,
                    'binding_keys' => $bindingKeys,
                ],
            ],
        ]);

        return new AmqpTransport($connection, $serializer);
    }
}
