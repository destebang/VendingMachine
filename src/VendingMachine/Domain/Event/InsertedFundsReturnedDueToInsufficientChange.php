<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A selection was rejected because the machine could not make the required
 * change (exact change only): every inserted coin was handed back, resetting
 * the balance.
 *
 * Carries the selector code of the selection that was rejected, so a consumer
 * knows which attempt the machine turned down — the machine only knows it
 * could not pay the change due for this selection.
 *
 * Recorded before the rejection is thrown, because the machine has already
 * changed. Kept distinct from `InsertedFundsReturned`: this is a machine
 * rejection, not a customer-initiated return.
 */
final readonly class InsertedFundsReturnedDueToInsufficientChange extends VendingMachineEvent
{
    public function __construct(
        VendingMachineId $vendingMachineId,
        VendingMachineState $state,
        public InsertedFunds $returnedFunds,
        public string $selectorCode,
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
     *     returnedFunds: array{denominationsInCents: list<int>},
     *     selectorCode: string
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
            'selectorCode' => $this->selectorCode,
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
     *     returnedFunds: array{denominationsInCents: list<int>},
     *     selectorCode: string
     * } $payload
     */
    public static function fromPayload(array $payload): static
    {
        return new self(
            VendingMachineId::fromString($payload['state']['id']),
            VendingMachineState::fromSnapshot($payload['state']),
            InsertedFunds::fromDenominations($payload['returnedFunds']['denominationsInCents']),
            $payload['selectorCode'],
            EventId::fromString($payload['eventId']),
        );
    }
}
