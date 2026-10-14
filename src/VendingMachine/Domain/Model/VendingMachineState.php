<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * Immutable snapshot of a vending machine: its identity and everything it
 * currently holds.
 *
 * This is the single outbound seam through which persistence and the read-model
 * projection read the aggregate, so the aggregate itself can expose behaviour
 * rather than a getter per field.
 */
final readonly class VendingMachineState
{
    public function __construct(
        public VendingMachineId $id,
        public AcceptedDenominations $acceptedDenominations,
        public Stock $stock,
        public CashReserve $cashReserve,
        public InsertedFunds $insertedFunds,
        public int $version,
    ) {}

    /**
     * Rebuilds a state from the plain snapshot shape the domain events
     * serialise it to (the inverse of `VendingMachineEvent::stateSnapshot()`),
     * so a stored event can be read back into domain objects without the
     * aggregate.
     *
     * @param array{
     *     id: string,
     *     acceptedDenominationsInCents: list<int>,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     insertedFunds: array{denominationsInCents: list<int>},
     *     version: int
     * } $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $stock = [];
        foreach ($snapshot['stock'] as $row) {
            $stock[] = Product::create($row['selectorCode'], Money::fromCents($row['priceInCents']), $row['stock']);
        }

        $cashReserveCounts = [];
        foreach ($snapshot['cashReserve'] as $row) {
            $cashReserveCounts[$row['denominationInCents']] = $row['quantity'];
        }

        return new self(
            VendingMachineId::fromString($snapshot['id']),
            AcceptedDenominations::fromCents($snapshot['acceptedDenominationsInCents']),
            Stock::fromProducts($stock),
            CashReserve::fromCounts($cashReserveCounts),
            InsertedFunds::fromDenominations($snapshot['insertedFunds']['denominationsInCents']),
            $snapshot['version'],
        );
    }
}
