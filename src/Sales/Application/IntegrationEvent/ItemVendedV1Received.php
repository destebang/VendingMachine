<?php

declare(strict_types=1);

namespace App\Sales\Application\IntegrationEvent;

use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use DateTimeImmutable;

/**
 * Version 1 of the vending machine's "an item was vended" message, as the
 * Sales context receives it.
 *
 * This is Sales' own class for the message, not the producer's: a context
 * owns its consumer-side contract so the producer can evolve its code without
 * breaking this one, and so this context never imports the producer's classes.
 * The wire shape is shared — the same JSON envelope and the same `data`
 * payload — but the class here carries only what the consumer needs.
 *
 * It extends the shared integration-event envelope so the same
 * `JsonEnvelopeSerializer` and `#[IntegrationEventSubscriber]` machinery that
 * every context uses can decode and route it.
 */
final readonly class ItemVendedV1Received extends IntegrationEvent
{
    public const string TYPE = 'vending_machine.item_vended.v1';

    /**
     * @param list<int> $changeInCents the coins handed back, largest first
     */
    public function __construct(
        string $id,
        string $vendingMachineId,
        int $aggregateVersion,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        public string $selectorCode,
        public int $priceInCents,
        public array $changeInCents,
    ) {
        parent::__construct($id, $vendingMachineId, $aggregateVersion, $occurredAt, $correlationId);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function source(): string
    {
        return 'vending-machine';
    }

    /**
     * @return array{vendingMachineId: string, selectorCode: string, priceInCents: int, changeInCents: list<int>}
     */
    public function data(): array
    {
        return [
            'vendingMachineId' => $this->aggregateId,
            'selectorCode' => $this->selectorCode,
            'priceInCents' => $this->priceInCents,
            'changeInCents' => $this->changeInCents,
        ];
    }

    /**
     * @param array<string, mixed> $data the decoded `data` object of the envelope
     */
    public static function fromEnvelope(
        string $id,
        string $aggregateId,
        int $aggregateVersion,
        DateTimeImmutable $occurredAt,
        string $correlationId,
        array $data,
    ): static {
        // The data object is the message's own `data()` output, guaranteed by
        // the published contract; the assertion is checked against that shape
        // by the analyzer.
        /** @var array{selectorCode: string, priceInCents: int, changeInCents: list<int>} $data */
        return new self(
            $id,
            $aggregateId,
            $aggregateVersion,
            $occurredAt,
            $correlationId,
            $data['selectorCode'],
            $data['priceInCents'],
            $data['changeInCents'],
        );
    }
}
