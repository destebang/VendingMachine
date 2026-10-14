<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A brand-new machine was provisioned: empty stock, empty cash reserve, no
 * inserted funds. The first event in a machine's life.
 */
final readonly class VendingMachineProvisioned extends VendingMachineEvent
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
