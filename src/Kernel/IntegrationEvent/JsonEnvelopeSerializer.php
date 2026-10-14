<?php

declare(strict_types=1);

namespace App\Kernel\IntegrationEvent;

use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Serializes integration events to and from the JSON envelope every
 * integration message travels in:
 *
 * ```json
 * {
 *   "id": "uuid", "type": "vending_machine.item_vended.v1", "source": "vending-machine",
 *   "occurredAt": "RFC3339", "aggregateId": "...", "aggregateVersion": 7,
 *   "correlationId": "...", "data": { "...": "..." }
 * }
 * ```
 *
 * Always JSON — never PHP's `serialize()`, whose output only PHP consumers
 * could read. Decoding needs the type→class registry (`$eventTypes`), since
 * the envelope intentionally carries no PHP class name.
 *
 * The redelivery count rides in a header, not the body: the body is the
 * published contract and does not change when a message is retried, but the
 * worker's retry strategy needs the count to survive the round-trip so a
 * message is retried a bounded number of times before it is dead-lettered.
 * (Symfony's own serializer stores stamps in `X-Message-Stamp-*` headers for
 * the same reason; this one only needs the redelivery count.)
 */
final class JsonEnvelopeSerializer implements SerializerInterface
{
    /** Header carrying the retry count across the broker round-trip. */
    private const string REDELIVERY_COUNT_HEADER = 'x-redelivery-count';

    /**
     * @param array<string, class-string<IntegrationEvent>> $eventTypes envelope type => event class
     */
    public function __construct(
        private readonly array $eventTypes,
    ) {}

    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            /** @var array{id: string, type: string, occurredAt: string, aggregateId: string, aggregateVersion: int, correlationId: string, data: array<string, mixed>} $decoded */
            $decoded = json_decode($encodedEnvelope['body'], associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MessageDecodingFailedException(
                'The integration event envelope is not valid JSON.',
                0,
                $exception,
            );
        }

        $eventClass = $this->eventTypes[$decoded['type']] ?? throw new MessageDecodingFailedException(sprintf(
            'No integration event is registered for type "%s".',
            $decoded['type'],
        ));

        $envelope = new Envelope($eventClass::fromEnvelope(
            $decoded['id'],
            $decoded['aggregateId'],
            $decoded['aggregateVersion'],
            new DateTimeImmutable($decoded['occurredAt']),
            $decoded['correlationId'],
            $decoded['data'],
        ));

        $redeliveryCount = $encodedEnvelope['headers'][self::REDELIVERY_COUNT_HEADER] ?? null;

        if ($redeliveryCount !== null) {
            $envelope = $envelope->with(new RedeliveryStamp((int) $redeliveryCount));
        }

        return $envelope;
    }

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $event = $envelope->getMessage();

        if (!$event instanceof IntegrationEvent) {
            throw new \InvalidArgumentException(sprintf(
                'Only integration events can travel on the integration bus, got "%s".',
                $event::class,
            ));
        }

        $headers = ['content-type' => 'application/json'];

        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);

        if ($redeliveryStamp instanceof RedeliveryStamp) {
            $headers[self::REDELIVERY_COUNT_HEADER] = (string) $redeliveryStamp->getRetryCount();
        }

        return [
            'body' => json_encode([
                'id' => $event->id,
                'type' => $event->type(),
                'source' => $event->source(),
                'occurredAt' => $event->occurredAt->format(DateTimeInterface::RFC3339),
                'aggregateId' => $event->aggregateId,
                'aggregateVersion' => $event->aggregateVersion,
                'correlationId' => $event->correlationId,
                'data' => $event->data(),
            ], JSON_THROW_ON_ERROR),
            'headers' => $headers,
        ];
    }
}
