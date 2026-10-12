<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A customer inserted a coin the machine accepts, growing the inserted funds.
 *
 * Carries the single `Denomination` as one field rather than its bare amount,
 * so the event stays in the domain's vocabulary.
 */
final readonly class CoinInserted extends VendingMachineEvent
{
    public function __construct(
        VendingMachineId $vendingMachineId,
        VendingMachineState $state,
        public Denomination $denomination,
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
     *     denomination: array{amountInCents: int}
     * }
     */
    public function payload(): array
    {
        return [
            'state' => $this->stateSnapshot(),
            'denomination' => [
                'amountInCents' => $this->denomination->amount()->amountInCents(),
            ],
        ];
    }
}
