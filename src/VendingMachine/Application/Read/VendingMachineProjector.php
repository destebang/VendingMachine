<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\Read;

use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\InsertedFundsReturned;
use App\VendingMachine\Domain\Event\InsertedFundsReturnedDueToInsufficientChange;
use App\VendingMachine\Domain\Event\ItemVended;
use App\VendingMachine\Domain\Event\VendingMachineProvisioned;
use App\VendingMachine\Domain\Event\VendingMachineServiced;
use App\VendingMachine\Domain\Model\VendingMachineState;
use App\VendingMachine\Domain\Read\MachineStateView;
use App\VendingMachine\Domain\Read\VendingMachineStateReadModelRepository;

/**
 * Keeps the Redis read model in step with the write side by reacting to the
 * published domain events, instead of the write handlers pushing to it.
 *
 * One method per event type, so the event bus can route each event to its own
 * reaction. Every event carries the resulting `VendingMachineState`, which is
 * projected into the read-side `MachineStateView`.
 */
final class VendingMachineProjector
{
    public function __construct(
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {}

    public function onVendingMachineProvisioned(VendingMachineProvisioned $event): void
    {
        $this->project($event->state);
    }

    public function onVendingMachineServiced(VendingMachineServiced $event): void
    {
        $this->project($event->state);
    }

    public function onCoinInserted(CoinInserted $event): void
    {
        $this->project($event->state);
    }

    public function onItemVended(ItemVended $event): void
    {
        $this->project($event->state);
    }

    public function onInsertedFundsReturned(InsertedFundsReturned $event): void
    {
        $this->project($event->state);
    }

    public function onInsertedFundsReturnedDueToInsufficientChange(InsertedFundsReturnedDueToInsufficientChange $event): void
    {
        $this->project($event->state);
    }

    private function project(VendingMachineState $state): void
    {
        $this->readModel->put(MachineStateView::fromState($state));
    }
}
