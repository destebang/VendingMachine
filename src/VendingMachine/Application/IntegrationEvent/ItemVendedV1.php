<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\IntegrationEvent;

use App\Shared\Application\IntegrationEvent\IntegrationEvent;
use DateTimeImmutable;

/**
 * Version 1 of the published "an item was vended" message — the vending
 * machine context's published language for the fact that a customer bought a
 * product.
 *
 * The payload is deliberately small and primitive: which machine, which
 * selector code, the price paid, and the change handed back. It carries no
 * domain objects and no internal state, so consuming contexts can depend on
 * it without coupling to this context's model. Changes that would break
 * consumers require a `v2` message instead.
 */
final readonly class ItemVendedV1 extends IntegrationEvent
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
        // The data object is this event's own `data()` output, guaranteed by
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
