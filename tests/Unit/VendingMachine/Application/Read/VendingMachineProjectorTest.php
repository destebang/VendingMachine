<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Application\Read;

use App\VendingMachine\Application\Read\VendingMachineProjector;
use App\VendingMachine\Domain\Event\CoinInserted;
use App\VendingMachine\Domain\Event\InsertedFundsReturned;
use App\VendingMachine\Domain\Event\InsertedFundsReturnedDueToInsufficientChange;
use App\VendingMachine\Domain\Event\ItemVended;
use App\VendingMachine\Domain\Event\VendingMachineProvisioned;
use App\VendingMachine\Domain\Event\VendingMachineServiced;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\ChangeDue;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendedItem;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Model\VendingMachineState;
use App\VendingMachine\Domain\Read\MachineStateView;
use App\VendingMachine\Domain\Read\VendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

final class VendingMachineProjectorTest extends TestCase
{
    public function test_every_event_type_projects_its_resulting_state(): void
    {
        $readModel = new class implements VendingMachineStateReadModelRepository {
            /**
             * @var list<MachineStateView>
             */
            public array $projected = [];

            public function put(MachineStateView $state): void
            {
                $this->projected[] = $state;
            }

            public function get(string $vendingMachineId): ?MachineStateView
            {
                return null;
            }
        };

        $projector = new VendingMachineProjector($readModel);

        $state = $this->state();
        $id = $state->id;
        $returnedFunds = InsertedFunds::fromDenominations([25]);

        $projector->onVendingMachineProvisioned(new VendingMachineProvisioned($id, $state));
        $projector->onVendingMachineServiced(new VendingMachineServiced($id, $state));
        $projector->onCoinInserted(new CoinInserted($id, $state, Denomination::of(Money::fromCents(25))));
        $projector->onItemVended(
            new ItemVended(
                $id,
                $state,
                new VendedItem('WATER', Money::fromCents(65), ChangeDue::fromCounts([25 => 1])),
            ),
        );
        $projector->onInsertedFundsReturned(new InsertedFundsReturned($id, $state, $returnedFunds));
        $projector->onInsertedFundsReturnedDueToInsufficientChange(new InsertedFundsReturnedDueToInsufficientChange(
            $id,
            $state,
            $returnedFunds,
        ));

        self::assertCount(6, $readModel->projected);

        $expected = MachineStateView::fromState($state);
        foreach ($readModel->projected as $projected) {
            self::assertSame($expected->id, $projected->id);
            self::assertSame($expected->stock, $projected->stock);
            self::assertSame($expected->cashReserve, $projected->cashReserve);
            self::assertSame($expected->insertedFundsInCents, $projected->insertedFundsInCents);
        }
    }

    private function state(): VendingMachineState
    {
        return new VendingMachineState(
            VendingMachineId::generate(),
            Stock::fromProducts([Product::create('WATER', Money::fromCents(65), 9)]),
            CashReserve::fromCounts([25 => 3]),
            InsertedFunds::fromDenominations([25]),
            4,
        );
    }
}
