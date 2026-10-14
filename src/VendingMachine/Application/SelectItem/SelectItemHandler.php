<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\SelectItem;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, lets the aggregate attempt the selection, and returns the
 * vended item plus the machine's resulting state.
 *
 * A rejection is not a no-op: an exact-change refund resets the balance, so the
 * write model and the Redis projection are refreshed for every outcome — success
 * or rejection — and the rejection then propagates untouched. A missing machine
 * surfaces as `VendingMachineNotFoundException` before any of this runs.
 */
#[CommandHandler]
final class SelectItemHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {}

    public function __invoke(SelectItem $command): SelectItemResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        try {
            $vendedItem = $vendingMachine->selectItem($command->selectorCode);
        } finally {
            $this->saveAndProject($vendingMachine);
        }

        return new SelectItemResult($vendedItem, MachineStateView::fromAggregate($vendingMachine));
    }

    private function saveAndProject(VendingMachine $vendingMachine): void
    {
        $this->vendingMachineRepository->save($vendingMachine);

        $state = MachineStateView::fromAggregate($vendingMachine);
        $this->readModel->put($state->id, $state->toArray());
    }
}
