<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\InsertCoin;

use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, records the inserted coin on the aggregate, persists the
 * new state and refreshes the Redis projection so the updated balance is
 * readable immediately.
 *
 * A denomination the machine does not accept is rejected by the domain and that
 * rejection propagates untouched, leaving both the write model and the
 * projection unchanged; a missing machine surfaces as
 * `VendingMachineNotFoundException`.
 */
final class InsertCoinHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {
    }

    public function __invoke(InsertCoin $command): InsertCoinResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        $vendingMachine->insertCoin($command->denomination);

        $this->vendingMachineRepository->save($vendingMachine);

        $state = MachineStateView::fromAggregate($vendingMachine);
        $this->readModel->put($state->id, $state->toArray());

        return new InsertCoinResult($state);
    }
}
