<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReturnCoin;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, takes back every inserted coin and returns them together
 * with the machine's resulting state.
 *
 * The recorded `InsertedFundsReturned` event is published once the command is
 * done, so the read-model projector refreshes the Redis projection for both a
 * real return and the no-op of returning with nothing inserted. A missing
 * machine surfaces as `VendingMachineNotFoundException` before any of this
 * runs.
 */
#[CommandHandler]
final class ReturnCoinHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(ReturnCoin $command): ReturnCoinResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        $returnedFunds = $vendingMachine->returnInsertedFunds();

        $this->vendingMachineRepository->save($vendingMachine);

        return new ReturnCoinResult(
            $returnedFunds->denominationsInCents(),
            MachineStateView::fromAggregate($vendingMachine),
        );
    }
}
