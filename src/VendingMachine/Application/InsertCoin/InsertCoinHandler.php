<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\InsertCoin;

use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, records the inserted coin on the aggregate and persists the
 * new state.
 *
 * A denomination the machine does not accept is rejected by the domain and that
 * rejection propagates untouched, leaving the write model unchanged; a missing
 * machine surfaces as `VendingMachineNotFoundException`. The read model is
 * projected from the `CoinInserted` event the save records, not pushed here.
 */
final class InsertCoinHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(InsertCoin $command): InsertCoinResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        $vendingMachine->insertCoin($command->denomination);

        $this->vendingMachineRepository->save($vendingMachine);

        return new InsertCoinResult($vendingMachine->state());
    }
}
