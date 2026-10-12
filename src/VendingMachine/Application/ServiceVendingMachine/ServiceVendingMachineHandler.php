<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ServiceVendingMachine;

use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, applies the declared stock and cash reserve (a replace)
 * and persists the new state. The domain guards against servicing
 * mid-transaction and that rejection propagates untouched; a missing machine
 * surfaces as `VendingMachineNotFoundException`.
 *
 * The read model is projected from the `VendingMachineServiced` event the save
 * records, not pushed here.
 */
final class ServiceVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(ServiceVendingMachine $command): ServiceVendingMachineResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        $vendingMachine->service($command->stock, $command->cashReserve);

        $this->vendingMachineRepository->save($vendingMachine);

        return new ServiceVendingMachineResult($vendingMachine->state());
    }
}
