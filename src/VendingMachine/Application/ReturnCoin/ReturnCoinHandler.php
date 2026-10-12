<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReturnCoin;

use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, takes back every inserted coin and returns them together
 * with the machine's resulting state.
 *
 * The write model is saved for both a real return and the no-op of returning
 * with nothing inserted, so the `InsertedFundsReturned` event (and therefore the
 * projection) always reflects the reset balance. A missing machine surfaces as
 * `VendingMachineNotFoundException` before any of this runs.
 */
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

        return new ReturnCoinResult($returnedFunds->denominationsInCents(), $vendingMachine->state());
    }
}
