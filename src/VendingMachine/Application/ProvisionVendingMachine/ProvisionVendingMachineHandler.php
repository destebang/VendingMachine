<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ProvisionVendingMachine;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Creates a new vending machine aggregate and persists it through the
 * write-side port. The recorded `VendingMachineProvisioned` event is published
 * once the command is done, and the read-model projector seeds the Redis read
 * model with the (empty) initial state so the query side can serve it
 * immediately.
 */
#[CommandHandler]
final class ProvisionVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(ProvisionVendingMachine $command): ProvisionVendingMachineResult
    {
        $vendingMachine = VendingMachine::create(VendingMachineId::generate(), $command->acceptedDenominations);

        $this->vendingMachineRepository->save($vendingMachine);

        return new ProvisionVendingMachineResult($vendingMachine->state()->id);
    }
}
