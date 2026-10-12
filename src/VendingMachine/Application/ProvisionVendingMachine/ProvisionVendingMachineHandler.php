<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ProvisionVendingMachine;

use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;

/**
 * Creates a new vending machine aggregate and persists it through the write-side
 * port. Projecting it into the read model is not this handler's job: the
 * `VendingMachineProvisioned` event it records is published and projected after
 * the command commits.
 */
final class ProvisionVendingMachineHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
    ) {}

    public function __invoke(ProvisionVendingMachine $command): ProvisionVendingMachineResult
    {
        $id = VendingMachineId::generate();

        $this->vendingMachineRepository->save(VendingMachine::create($id));

        return new ProvisionVendingMachineResult($id);
    }
}
