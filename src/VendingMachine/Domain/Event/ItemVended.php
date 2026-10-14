<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\ChangeDue;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\VendedItem;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A customer successfully selected a product: it was vended, any change due was
 * paid from the cash reserve, and the inserted funds were reset.
 *
 * Carries the `VendedItem` (product and change handed back) as one field.
 */
final readonly class ItemVended extends VendingMachineEvent
{
    public function __construct(
        VendingMachineId $vendingMachineId,
        VendingMachineState $state,
        public VendedItem $vendedItem,
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
     *     vendedItem: array{selectorCode: string, priceInCents: int, change: list<int>}
     * }
     */
    public function payload(): array
    {
        return [
            'eventId' => $this->eventId->toString(),
            'state' => $this->stateSnapshot(),
            'vendedItem' => [
                'selectorCode' => $this->vendedItem->selectorCode,
                'priceInCents' => $this->vendedItem->price->amountInCents(),
                'change' => $this->vendedItem->changeDue->denominationsInCents(),
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
     *     vendedItem: array{selectorCode: string, priceInCents: int, change: list<int>}
     * } $payload
     */
    public static function fromPayload(array $payload): static
    {
        // The change is stored as the flat coins handed back (largest first);
        // a count per denomination is the canonical in-memory shape.
        $change = ChangeDue::fromCounts(array_count_values($payload['vendedItem']['change']));

        return new self(
            VendingMachineId::fromString($payload['state']['id']),
            VendingMachineState::fromSnapshot($payload['state']),
            new VendedItem(
                $payload['vendedItem']['selectorCode'],
                Money::fromCents($payload['vendedItem']['priceInCents']),
                $change,
            ),
            EventId::fromString($payload['eventId']),
        );
    }
}
