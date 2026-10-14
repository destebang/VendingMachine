<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A technician declared the machine's true stock and cash reserve, replacing
 * whatever was there. Rejected while a transaction is pending, so a serviced
 * event never overlaps inserted funds.
 */
final readonly class VendingMachineServiced extends VendingMachineEvent
{
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
     *     }
     * }
     */
    public function payload(): array
    {
        return [
            'eventId' => $this->eventId->toString(),
            'state' => $this->stateSnapshot(),
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
     *     }
     * } $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            VendingMachineId::fromString($payload['state']['id']),
            VendingMachineState::fromSnapshot($payload['state']),
            EventId::fromString($payload['eventId']),
        );
    }
}
