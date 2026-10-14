<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A customer inserted a coin the machine accepts, growing the inserted funds.
 *
 * Carries the single `Denomination` as one field rather than its bare amount,
 * so the event stays in the domain's vocabulary.
 */
final readonly class CoinInserted extends VendingMachineEvent
{
    public function __construct(
        VendingMachineId $vendingMachineId,
        VendingMachineState $state,
        public Denomination $denomination,
        ?EventId $eventId = null,
    ) {
        parent::__construct($vendingMachineId, $state, $eventId);
    }

    /**
     * @return array{
     *     eventId: string,
     *     state: array{
     *         id: string,
     *         acceptedDenominationsInCents: list<int>,
     *         stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *         cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *         insertedFunds: array{denominationsInCents: list<int>},
     *         version: int
     *     },
     *     denomination: array{amountInCents: int}
     * }
     */
    public function payload(): array
    {
        return [
            'eventId' => $this->eventId->toString(),
            'state' => $this->stateSnapshot(),
            'denomination' => [
                'amountInCents' => $this->denomination->amount()->amountInCents(),
            ],
        ];
    }

    /**
     * Rebuilds the event from its persisted payload, identity included, so
     * infrastructure can rehydrate it without knowing its shape.
     *
     * @param array{
     *     eventId: string,
     *     state: array{
     *         id: string,
     *         acceptedDenominationsInCents: list<int>,
     *         stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *         cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *         insertedFunds: array{denominationsInCents: list<int>},
     *         version: int
     *     },
     *     denomination: array{amountInCents: int}
     * } $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            VendingMachineId::fromString($payload['state']['id']),
            VendingMachineState::fromSnapshot($payload['state']),
            Denomination::of(Money::fromCents($payload['denomination']['amountInCents'])),
            EventId::fromString($payload['eventId']),
        );
    }
}
