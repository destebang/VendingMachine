<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\SelectItem;

use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, lets the aggregate attempt the selection, and returns the
 * vended item plus the machine's resulting state.
 *
 * A rejection is not a no-op: an exact-change refund resets the balance, so the
 * write model is committed for every outcome — success or rejection — and the
 * rejection then propagates untouched. That commit is what records the
 * `InsertedFundsReturnedDueToInsufficientChange` event the projection reacts to.
 * A missing machine surfaces as `VendingMachineNotFoundException` before any of
 * this runs.
 */
final class SelectItemHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(SelectItem $command): SelectItemResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        try {
            $vendedItem = $vendingMachine->selectItem($command->selectorCode);
        } finally {
            $this->vendingMachineRepository->save($vendingMachine);
        }

        return new SelectItemResult($vendedItem, $vendingMachine->state());
    }
}
