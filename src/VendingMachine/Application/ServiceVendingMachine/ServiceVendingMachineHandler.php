<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ServiceVendingMachine;

use App\VendingMachine\Application\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, applies the declared stock and cash reserve (a replace),
 * persists the new state and refreshes the Redis projection. The domain guards
 * against servicing mid-transaction and that rejection propagates untouched.
 */
final class ServiceVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {
    }

    public function __invoke(ServiceVendingMachine $command): ServiceVendingMachineResult
    {
        $vendingMachine = $this->vendingMachineRepository->find($command->vendingMachineId);

        if ($vendingMachine === null) {
            throw VendingMachineNotFoundException::withId($command->vendingMachineId);
        }

        $vendingMachine->service($command->stock, $command->cashReserve);

        $this->vendingMachineRepository->save($vendingMachine);

        $state = MachineStateView::fromAggregate($vendingMachine);
        $this->readModel->put($state->id, $state->toArray());

        return new ServiceVendingMachineResult($state);
    }
}
