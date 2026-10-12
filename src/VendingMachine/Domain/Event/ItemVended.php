<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

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
    ) {
        parent::__construct($vendingMachineId, $state);
    }

    /**
     * @return array{
     *     state: array{
     *         id: string,
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
            'state' => $this->stateSnapshot(),
            'vendedItem' => [
                'selectorCode' => $this->vendedItem->selectorCode,
                'priceInCents' => $this->vendedItem->price->amountInCents(),
                'change' => $this->vendedItem->changeDue->denominationsInCents(),
            ],
        ];
    }
}
