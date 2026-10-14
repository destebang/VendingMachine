<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\InsertCoin;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, records the inserted coin on the aggregate and persists
 * the new state. The recorded `CoinInserted` event is published once the
 * command is done, and the read-model projector keeps the Redis balance
 * readable immediately.
 *
 * A denomination the machine does not accept is rejected by the domain and that
 * rejection propagates untouched, leaving both the write model and the
 * projection unchanged; a missing machine surfaces as
 * `VendingMachineNotFoundException`.
 */
#[CommandHandler]
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

        return new InsertCoinResult(MachineStateView::fromAggregate($vendingMachine));
    }
}
