<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReadModel;

use App\Shared\Application\Bus\Attribute\DomainEventSubscriber;
use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\InsertedFundsReturned;
use App\VendingMachine\Domain\Event\InsertedFundsReturnedDueToInsufficientChange;
use App\VendingMachine\Domain\Event\ItemVended;
use App\VendingMachine\Domain\Event\VendingMachineProvisioned;
use App\VendingMachine\Domain\Event\VendingMachineServiced;
use App\VendingMachine\Domain\Model\VendingMachineState;

/**
 * Keeps the Redis read model in step with the write side by reacting to the
 * published domain events, instead of the write handlers pushing to it.
 *
 * One method per event, so the domain event bus routes each recorded fact to
 * its own reaction. Every event carries the resulting `VendingMachineState`,
 * which is projected into the read-side `MachineStateView`; because the domain
 * event bus is synchronous, the projection is up to date by the time the
 * command returns.
 */
#[DomainEventSubscriber]
final class VendingMachineReadModelProjector
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
        $view = MachineStateView::fromState($state);

        $this->readModel->put($view->id, $view->toArray());
    }
}
