<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\SelectItem;

use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Domain\Model\VendedItem;

/**
 * Result of selecting a product: the item that was vended, the change handed
 * back, and the machine's state afterwards — whose `insertedFundsInCents` is the
 * remaining balance (zero after a successful vend or an exact-change refund).
 */
final class SelectItemResult
{
    public function __construct(
        public readonly VendedItem $vendedItem,
        public readonly MachineStateView $state,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     selectorCode: string,
     *     priceInCents: int,
     *     change: list<int>,
     *     insertedFundsInCents: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->state->id,
            'selectorCode' => $this->vendedItem->selectorCode,
            'priceInCents' => $this->vendedItem->price->amountInCents(),
            'change' => $this->vendedItem->changeDue->denominationsInCents(),
            'insertedFundsInCents' => $this->state->insertedFundsInCents,
        ];
    }
}
