<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Application\IntegrationEvent;

use App\Kernel\IntegrationEvent\JsonEnvelopeSerializer;
use App\Tests\Unit\AsyncApi\YamlSchemaResolver;
use App\VendingMachine\Application\IntegrationEvent\ItemVendedV1;
use DateTimeImmutable;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the code and the AsyncAPI contract honest with each other.
 *
 * The message the real `JsonEnvelopeSerializer` puts on the wire must validate
 * against the payload schema the spec declares, and so must the example the
 * spec ships. Renaming or removing a field on either side — the PHP event, the
 * serializer, or the spec — makes one of these fail, so the spec and the code
 * cannot drift apart silently.
 */
final class ItemVendedV1ContractTest extends TestCase
{
    /** The producer document, relative to the project root. */
    private const string SPEC = 'docs/asyncapi/vending-machine.asyncapi.yaml';

    /** The base URI the specs are addressed by; see {@see YamlSchemaResolver}. */
    private const string BASE_URI = 'https://vending-machine/';

    public function test_the_serialized_message_validates_against_the_spec_schema(): void
    {
        $this->assertValid($this->validator()->validate($this->serializedMessage(), $this->messageSchemaUri()));
    }

    public function test_the_spec_example_validates_against_the_spec_schema(): void
    {
        /** @var list<array{payload: array<string, mixed>}> $examples */
        $examples = $this->message()['examples'];
        $example = $examples[0]['payload'];

        $this->assertValid($this->validator()->validate(
            json_decode(json_encode($example, JSON_THROW_ON_ERROR), associative: false, flags: JSON_THROW_ON_ERROR),
            $this->messageSchemaUri(),
        ));
    }

    private function validator(): Validator
    {
        return new Validator(new SchemaLoader(new SchemaParser(), new YamlSchemaResolver(self::root())));
    }

    /**
     * The exact message the serializer publishes, decoded from its JSON body.
     */
    private function serializedMessage(): mixed
    {
        $serializer = new JsonEnvelopeSerializer(['vending_machine.item_vended.v1' => ItemVendedV1::class]);

        $encoded = $serializer->encode(new Envelope(new ItemVendedV1(
            id: '01a12f94-62ac-771b-a72a-f8a35ea379bb',
            vendingMachineId: '0190f0c2-1a2b-7c3d-8e4f-0123456789ab',
            aggregateVersion: 7,
            occurredAt: new DateTimeImmutable('2026-10-14T10:30:00+00:00'),
            correlationId: '01a12f94-62ac-771b-a72a-f8a35ea379bb',
            selectorCode: 'WATER',
            priceInCents: 65,
            changeInCents: [25, 10],
        )));

        return json_decode($encoded['body'], associative: false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The absolute URI of the payload schema the spec's message points at, so
     * the test follows the spec rather than a hardcoded path.
     */
    private function messageSchemaUri(): string
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->message()['payload'];

        /** @var string $ref */
        $ref = $payload['$ref'];

        $file = realpath(self::root() . '/' . dirname(self::SPEC) . '/' . $ref);
        if ($file === false) {
            self::fail(sprintf('The spec points at a missing payload schema: %s', $ref));
        }

        return self::BASE_URI . ltrim(substr($file, strlen(self::root())), characters: '/');
    }

    /**
     * The spec's `vending_machine.item_vended.v1` message.
     *
     * @return array<string, mixed>
     */
    private function message(): array
    {
        /** @var array<string, mixed> $components */
        $components = $this->spec()['components'];

        /** @var array<string, mixed> $messages */
        $messages = $components['messages'];

        /** @var array<string, mixed> $message */
        $message = $messages['itemVendedV1'];

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile(self::root() . '/' . self::SPEC);

        return $spec;
    }

    private function assertValid(ValidationResult $result): void
    {
        self::assertTrue($result->isValid(), (string) $result->error());
    }

    private static function root(): string
    {
        return dirname(__DIR__, levels: 5);
    }
}
