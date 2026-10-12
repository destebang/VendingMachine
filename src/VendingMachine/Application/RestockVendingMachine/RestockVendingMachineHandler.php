<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\RestockVendingMachine;

use App\VendingMachine\Application\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, applies the declared stock/change (a replace), persists
 * the new state and refreshes the Redis projection. The domain guards against
 * servicing mid-transaction and that rejection propagates untouched.
 */
final class RestockVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {
    }

    public function __invoke(RestockVendingMachine $command): RestockVendingMachineResult
    {
        $vendingMachine = $this->vendingMachineRepository->find($command->vendingMachineId);

        if ($vendingMachine === null) {
            throw VendingMachineNotFoundException::withId($command->vendingMachineId);
        }

        $vendingMachine->restock($command->stock, $command->cashReserve);

        $this->vendingMachineRepository->save($vendingMachine);

        $state = MachineStateView::fromAggregate($vendingMachine);
        $this->readModel->put($state->id, $state->toArray());

        return new RestockVendingMachineResult($state);
    }
}
