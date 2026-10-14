<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sales\Application\IntegrationEvent;

use App\Kernel\IntegrationEvent\JsonEnvelopeSerializer;
use App\Sales\Application\IntegrationEvent\ItemVendedV1Received;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the Sales consumer honest with the producer's contract.
 *
 * The producer document ships an example of `vending_machine.item_vended.v1`.
 * Decoding that exact example through the Sales serializer must yield the
 * Sales context's own message class, so the two sides cannot drift: if the
 * producer renames or removes a field, this test fails on the consumer too.
 */
final class ItemVendedV1ReceivedContractTest extends TestCase
{
    /** The producer document, relative to the project root. */
    private const string PRODUCER_SPEC = 'docs/asyncapi/vending-machine.asyncapi.yaml';

    public function test_the_producer_example_decodes_into_the_sales_message(): void
    {
        $serializer = new JsonEnvelopeSerializer([
            ItemVendedV1Received::TYPE => ItemVendedV1Received::class,
        ]);

        $envelope = $serializer->decode([
            'body' => json_encode($this->producerExample(), JSON_THROW_ON_ERROR),
        ]);

        $message = $envelope->getMessage();

        self::assertInstanceOf(ItemVendedV1Received::class, $message);
        self::assertSame('01a12f94-62ac-771b-a72a-f8a35ea379bb', $message->id);
        self::assertSame('01a12f94-62ac-771b-a72a-f8a35ea379bb', $message->correlationId);
        self::assertSame('0190f0c2-1a2b-7c3d-8e4f-0123456789ab', $message->aggregateId);
        self::assertSame(7, $message->aggregateVersion);
        self::assertSame('2026-10-14T10:30:00+00:00', $message->occurredAt->format(DateTimeInterface::RFC3339));
        self::assertSame('vending_machine.item_vended.v1', $message->type());
        self::assertSame('WATER', $message->selectorCode);
        self::assertSame(65, $message->priceInCents);
        self::assertSame([25, 10], $message->changeInCents);
        self::assertSame(
            [
                'vendingMachineId' => '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'changeInCents' => [25, 10],
            ],
            $message->data(),
        );
    }

    /**
     * The producer spec's `item_vended.v1` example payload.
     *
     * @return array<string, mixed>
     */
    private function producerExample(): array
    {
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile(self::root() . '/' . self::PRODUCER_SPEC);

        /** @var array<string, mixed> $components */
        $components = $spec['components'];

        /** @var array<string, mixed> $messages */
        $messages = $components['messages'];

        /** @var array<string, mixed> $message */
        $message = $messages['itemVendedV1'];

        /** @var list<array{payload: array<string, mixed>}> $examples */
        $examples = $message['examples'];

        return $examples[0]['payload'];
    }

    private static function root(): string
    {
        return dirname(__DIR__, levels: 5);
    }
}
