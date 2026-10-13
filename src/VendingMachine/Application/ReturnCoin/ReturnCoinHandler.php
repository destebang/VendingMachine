<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReturnCoin;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, takes back every inserted coin and returns them together
 * with the machine's resulting state.
 *
 * The write model and the Redis projection are refreshed for both a real return
 * and the no-op of returning with nothing inserted, so the projection always
 * reflects the (reset) balance. A missing machine surfaces as
 * `VendingMachineNotFoundException` before any of this runs.
 */
#[CommandHandler]
final class ReturnCoinHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {}

    public function __invoke(ReturnCoin $command): ReturnCoinResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        $returnedFunds = $vendingMachine->returnInsertedFunds();

        $this->vendingMachineRepository->save($vendingMachine);

        $state = MachineStateView::fromAggregate($vendingMachine);
        $this->readModel->put($state->id, $state->toArray());

        return new ReturnCoinResult($returnedFunds->denominationsInCents(), $state);
    }
}
