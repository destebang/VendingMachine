<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ProvisionVendingMachine;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Creates a new vending machine aggregate, persists it through the write-side
 * port, and seeds the Redis read model with its (empty) initial state so the
 * query side can serve it immediately.
 */
#[CommandHandler]
final class ProvisionVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {}

    public function __invoke(ProvisionVendingMachine $command): ProvisionVendingMachineResult
    {
        $vendingMachine = VendingMachine::create(VendingMachineId::generate(), $command->acceptedDenominations);
        $state = $vendingMachine->state();

        $this->vendingMachineRepository->save($vendingMachine);
        $this->readModel->put($state->id->toString(), MachineStateView::fromAggregate($vendingMachine)->toArray());

        return new ProvisionVendingMachineResult($state->id);
    }
}
