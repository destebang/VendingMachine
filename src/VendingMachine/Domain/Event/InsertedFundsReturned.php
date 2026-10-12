<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

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
     *     returnedFunds: array{denominationsInCents: list<int>}
     * }
     */
    public function payload(): array
    {
        return [
            'state' => $this->stateSnapshot(),
            'returnedFunds' => [
                'denominationsInCents' => $this->returnedFunds->denominationsInCents(),
            ],
        ];
    }
}
