<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\SelectItem;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, lets the aggregate attempt the selection, and returns the
 * vended item plus the machine's resulting state.
 *
 * A rejection is not a no-op: an exact-change refund resets the balance, so the
 * write model is saved for every outcome — success or rejection — and the
 * rejection then propagates untouched. The recorded event is published once the
 * command is done, so the read-model projector refreshes the Redis projection
 * for both outcomes too. A missing machine surfaces as
 * `VendingMachineNotFoundException` before any of this runs.
 */
#[CommandHandler]
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

        return new SelectItemResult($vendedItem, MachineStateView::fromAggregate($vendingMachine));
    }
}
