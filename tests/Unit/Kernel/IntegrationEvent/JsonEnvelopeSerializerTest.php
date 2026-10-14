<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel\IntegrationEvent;

use App\Kernel\IntegrationEvent\JsonEnvelopeSerializer;
use App\VendingMachine\Application\IntegrationEvent\ItemVendedV1;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

final class JsonEnvelopeSerializerTest extends TestCase
{
    /** @var array<string, class-string<ItemVendedV1>> */
    private const array EVENT_TYPES = ['vending_machine.item_vended.v1' => ItemVendedV1::class];

    public function test_encode_produces_the_published_envelope(): void
    {
        $event = $this->event();

        $encoded = new JsonEnvelopeSerializer(self::EVENT_TYPES)->encode(new Envelope($event));

        self::assertSame('application/json', $encoded['headers']['content-type']);
        self::assertSame(
            [
                'id' => '01a12f94-62ac-771b-a72a-f8a35ea379bb',
                'type' => 'vending_machine.item_vended.v1',
                'source' => 'vending-machine',
                'occurredAt' => '2026-10-14T10:30:00+00:00',
                'aggregateId' => '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
                'aggregateVersion' => 7,
                'correlationId' => '01a12f94-62ac-771b-a72a-f8a35ea379bb',
                'data' => [
                    'vendingMachineId' => '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
                    'selectorCode' => 'WATER',
                    'priceInCents' => 65,
                    'changeInCents' => [25, 10],
                ],
            ],
            json_decode($encoded['body'], associative: true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_published_envelope_decodes_back_to_the_same_event(): void
    {
        $serializer = new JsonEnvelopeSerializer(self::EVENT_TYPES);

        $decoded = $serializer->decode($serializer->encode(new Envelope($this->event())))->getMessage();

        self::assertInstanceOf(ItemVendedV1::class, $decoded);
        self::assertSame('01a12f94-62ac-771b-a72a-f8a35ea379bb', $decoded->id);
        self::assertSame('0190f0c2-1a2b-7c3d-8e4f-0123456789ab', $decoded->aggregateId);
        self::assertSame(7, $decoded->aggregateVersion);
        self::assertSame('2026-10-14T10:30:00+00:00', $decoded->occurredAt->format(\DateTimeInterface::RFC3339));
        self::assertSame('01a12f94-62ac-771b-a72a-f8a35ea379bb', $decoded->correlationId);
        self::assertSame($this->event()->data(), $decoded->data());
    }

    public function test_decode_fails_for_a_type_no_event_is_registered_for(): void
    {
        $serializer = new JsonEnvelopeSerializer(self::EVENT_TYPES);

        $this->expectException(MessageDecodingFailedException::class);

        $serializer->decode([
            'body' => json_encode([
                'id' => '01a12f94-62ac-771b-a72a-f8a35ea379bb',
                'type' => 'vending_machine.unknown.v9',
                'occurredAt' => '2026-10-14T10:30:00+00:00',
                'aggregateId' => '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
                'aggregateVersion' => 7,
                'correlationId' => '01a12f94-62ac-771b-a72a-f8a35ea379bb',
                'data' => [],
            ], JSON_THROW_ON_ERROR),
            'headers' => [],
        ]);
    }

    public function test_encode_refuses_anything_but_an_integration_event(): void
    {
        $serializer = new JsonEnvelopeSerializer(self::EVENT_TYPES);

        $this->expectException(\InvalidArgumentException::class);

        $serializer->encode(new Envelope(new \stdClass()));
    }

    private function event(): ItemVendedV1
    {
        return new ItemVendedV1(
            id: '01a12f94-62ac-771b-a72a-f8a35ea379bb',
            vendingMachineId: '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
            aggregateVersion: 7,
            occurredAt: new DateTimeImmutable('2026-10-14T10:30:00+00:00'),
            correlationId: '01a12f94-62ac-771b-a72a-f8a35ea379bb',
            selectorCode: 'WATER',
            priceInCents: 65,
            changeInCents: [25, 10],
        );
    }
}
