<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Persistence\Mongo;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * Doctrine ODM document for a vending machine. Deliberately kept separate
 * from the `VendingMachine` domain aggregate: this class only exists to
 * describe how the write model is stored in MongoDB, and carries no
 * business behaviour. `VendingMachineRepository` is the only place that
 * translates between the two.
 */
#[ODM\Document(collection: 'vending_machines')]
#[ODM\Index(keys: ['pendingEvents.eventId' => 'asc'])]
class VendingMachineDocument
{
    #[ODM\Id(strategy: 'none', type: 'string')]
    public string $id;

    #[ODM\Field(type: 'int')]
    #[ODM\Version]
    public int $version = 0;

    /**
     * The denominations the machine accepts from a customer, in cents.
     * Machine configuration set at provisioning and replaceable at service,
     * stored as a list of amounts.
     *
     * @var list<int>
     */
    #[ODM\Field(type: 'raw')]
    public array $acceptedDenominationsInCents = [];

    /**
     * The literal coins the customer has inserted, in cents, in the order they
     * were inserted. Stored as a list (not a total) so a return can hand back
     * exactly these coins.
     *
     * @var list<int>
     */
    #[ODM\Field(type: 'raw')]
    public array $insertedDenominationsInCents = [];

    /**
     * Stock as a list of flat rows: selector code, price in cents and units
     * loaded. Stored as a list (not a map) so MongoDB keeps it ordered and
     * round-trips it as a plain array.
     *
     * @var list<array{selectorCode: string, priceInCents: int, stock: int}>
     */
    #[ODM\Field(type: 'raw')]
    public array $stock = [];

    /**
     * Cash reserve as a list of flat rows: denomination in cents and number of
     * units held.
     *
     * @var list<array{denominationInCents: int, quantity: int}>
     */
    #[ODM\Field(type: 'raw')]
    public array $cashReserve = [];

    /**
     * The embedded outbox: the domain events recorded by the last save(s),
     * waiting for the relay to publish them as integration events. Written in
     * the same flush as the state change, so a state transition and its
     * outbox entries are atomic. The multikey index lets the relay find
     * documents with pending events cheaply and `$pull` each published entry
     * by its `eventId`.
     *
     * @var list<array{eventId: string, type: string, payload: array<string, mixed>, aggregateVersion: int, occurredAt: string}>
     */
    #[ODM\Field(type: 'raw')]
    public array $pendingEvents = [];

    public function __construct(string $id, int $version = 0)
    {
        $this->id = $id;
        $this->version = $version;
    }
}
