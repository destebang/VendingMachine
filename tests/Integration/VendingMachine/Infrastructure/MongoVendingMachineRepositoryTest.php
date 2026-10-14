<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\DocumentManagerFactory;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\InsertedFunds;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use Doctrine\ODM\MongoDB\LockException;
use PHPUnit\Framework\TestCase;

final class MongoVendingMachineRepositoryTest extends TestCase
{
    public function test_it_persists_and_retrieves_a_vending_machine(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        self::assertNull($repository->find($id));

        $repository->save(VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100])));

        $found = $repository->find($id);

        self::assertNotNull($found);
        self::assertTrue($found->state()->id->equals($id));
    }

    public function test_it_round_trips_stock_reserve_and_inserted_funds(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $repository->save(VendingMachine::reconstitute(
            $id,
            AcceptedDenominations::fromCents([10, 50, 100]),
            InsertedFunds::fromDenominations([25, 25]),
            Stock::fromProducts([
                Product::create('WATER', Money::fromCents(65), 4),
                Product::create('CHIPS', Money::fromCents(120), 7),
            ]),
            CashReserve::fromCounts([100 => 5, 50 => 3]),
            0,
        ));

        $found = $repository->find($id);

        self::assertNotNull($found);
        self::assertSame([10, 50, 100], $found->state()->acceptedDenominations->denominationsInCents());
        self::assertSame(50, $found->state()->insertedFunds->amount()->amountInCents());
        self::assertSame([25, 25], $found->state()->insertedFunds->denominationsInCents());
        self::assertSame([100 => 5, 50 => 3], $found->state()->cashReserve->counts());
        self::assertSame(4, $found->state()->stock->quantityOf('WATER'));
        self::assertSame(65, $found->state()->stock->products()['WATER']->price()->amountInCents());
        self::assertSame(7, $found->state()->stock->quantityOf('CHIPS'));
    }

    public function test_get_returns_the_stored_machine(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $repository->save(VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100])));

        $found = $repository->get($id);

        self::assertTrue($found->state()->id->equals($id));
    }

    public function test_get_fails_when_the_machine_does_not_exist(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());

        $this->expectException(VendingMachineNotFoundException::class);

        $repository->get(VendingMachineId::generate());
    }

    public function test_the_stored_version_stays_in_step_with_the_aggregates_mutations(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $machine = VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100]));
        $repository->save($machine);

        // Provisioning is the first recorded mutation: the document is seeded at version 1.
        self::assertSame(1, $repository->get($id)->state()->version);

        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $repository->save($machine);

        self::assertSame(2, $repository->get($id)->state()->version);
    }

    public function test_a_stale_save_is_rejected_by_the_optimistic_lock(): void
    {
        $reader = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $reader->save(VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100])));

        // Two aggregates for the same machine, each loaded into its own
        // document manager, so each believes the machine is still at version 1.
        $fresh = $reader->get($id);
        $staleRepository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $stale = $staleRepository->get($id);

        $fresh->insertCoin(Denomination::of(Money::fromCents(25)));
        $reader->save($fresh);

        $stale->insertCoin(Denomination::of(Money::fromCents(10)));

        $this->expectException(LockException::class);

        // The stale aggregate's save still expects version 1, but the machine
        // is at version 2 now, so the optimistic lock rejects it.
        $staleRepository->save($stale);
    }
}
