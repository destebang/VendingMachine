<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\EventId;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * Base for every domain event a vending machine records.
 *
 * Every machine event carries the same four things: the identity of the
 * aggregate that recorded it, the event's own identity, the version the
 * machine was left at, and the full resulting state. Concrete events add the
 * action-specific data — the coin inserted, the item vended, the funds
 * returned.
 *
 * Events are immutable facts: readonly, and free of any timestamp. Stamping
 * `occurredAt` is a storage concern, handled later by infrastructure.
 *
 * Every concrete event also exposes a `fromPayload()` named constructor that
 * rebuilds it from `payload()` alone, so infrastructure can store an event and
 * read it back — and route or inspect it — without knowing its shape.
 */
abstract readonly class VendingMachineEvent implements DomainEvent
{
    /**
     * The event's identity, minted when the event is recorded. Supplied only
     * when rehydrating an event from its stored payload; a freshly recorded
     * fact always gets a fresh id.
     */
    public EventId $eventId;

    public function __construct(
        public VendingMachineId $vendingMachineId,
        public VendingMachineState $state,
        ?EventId $eventId = null,
    ) {
        $this->eventId = $eventId ?? EventId::generate();
    }

    final public function aggregateId(): string
    {
        return $this->vendingMachineId->toString();
    }

    final public function eventId(): string
    {
        return $this->eventId->toString();
    }

    /**
     * The machine's version once the mutation this event records was applied.
     * The aggregate bumps its version exactly once per mutation, before the
     * event is constructed, so this is the same value carried in the state
     * snapshot — the version the machine was left at, not the one it started
     * from.
     */
    final public function aggregateVersion(): int
    {
        return $this->state->version;
    }

    /**
     * The full resulting machine state as a plain array, ready to be stored.
     *
     * Shared by every concrete event so the snapshot shape is written once, by
     * hand, instead of being duplicated or re-derived by reflection. Inserted
     * funds keep the literal coins the customer put in — the write-side state
     * is not flattened to a running total.
     *
     * @return array{
     *     id: string,
     *     acceptedDenominationsInCents: list<int>,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     insertedFunds: array{denominationsInCents: list<int>},
     *     version: int
     * }
     */
    final protected function stateSnapshot(): array
    {
        $stock = [];
        foreach ($this->state->stock->products() as $product) {
            $stock[] = [
                'selectorCode' => $product->selectorCode(),
                'priceInCents' => $product->price()->amountInCents(),
                'stock' => $product->stock(),
            ];
        }

        $cashReserve = [];
        foreach ($this->state->cashReserve->counts() as $denominationInCents => $quantity) {
            $cashReserve[] = [
                'denominationInCents' => $denominationInCents,
                'quantity' => $quantity,
            ];
        }

        return [
            'id' => $this->state->id->toString(),
            'acceptedDenominationsInCents' => $this->state->acceptedDenominations->denominationsInCents(),
            'stock' => $stock,
            'cashReserve' => $cashReserve,
            'insertedFunds' => [
                'denominationsInCents' => $this->state->insertedFunds->denominationsInCents(),
            ],
            'version' => $this->state->version,
        ];
    }
}
