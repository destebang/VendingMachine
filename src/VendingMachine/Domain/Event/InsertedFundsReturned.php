<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * Customer action: every inserted coin was handed back, ending the pending
 * transaction without a sale.
 *
 * Distinct from `InsertedFundsReturnedDueToInsufficientChange`: here the
 * customer chose to return, there the machine rejected the sale. The two are
 * never collapsed into one event with a reason.
 */
final readonly class InsertedFundsReturned extends VendingMachineEvent
{
    public function __construct(
        VendingMachineId $vendingMachineId,
        VendingMachineState $state,
        public InsertedFunds $returnedFunds,
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
     *     returnedFunds: array{denominationsInCents: list<int>}
     * }
     */
    public function payload(): array
    {
        return [
            'eventId' => $this->eventId->toString(),
            'state' => $this->stateSnapshot(),
            'returnedFunds' => [
                'denominationsInCents' => $this->returnedFunds->denominationsInCents(),
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
     *     returnedFunds: array{denominationsInCents: list<int>}
     * } $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            VendingMachineId::fromString($payload['state']['id']),
            VendingMachineState::fromSnapshot($payload['state']),
            InsertedFunds::fromDenominations($payload['returnedFunds']['denominationsInCents']),
            EventId::fromString($payload['eventId']),
        );
    }
}
