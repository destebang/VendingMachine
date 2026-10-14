<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ServiceVendingMachine;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Loads the machine, applies the declared stock and cash reserve (a replace)
 * and persists the new state. The recorded `VendingMachineServiced` event is
 * published once the command is done, and the read-model projector refreshes
 * the Redis projection. The domain guards against servicing mid-transaction
 * and that rejection propagates untouched; a missing machine surfaces as
 * `VendingMachineNotFoundException`.
 */
#[CommandHandler]
final class ServiceVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(ServiceVendingMachine $command): ServiceVendingMachineResult
    {
        $vendingMachine = $this->vendingMachineRepository->get($command->vendingMachineId);

        $vendingMachine->service($command->stock, $command->cashReserve, $command->acceptedDenominations);

        $this->vendingMachineRepository->save($vendingMachine);

        return new ServiceVendingMachineResult(MachineStateView::fromAggregate($vendingMachine));
    }
}
