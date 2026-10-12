<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Event;

use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * A selection was rejected because the machine could not make the required
 * change (exact change only): every inserted coin was handed back, resetting
 * the balance.
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
