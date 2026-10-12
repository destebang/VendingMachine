<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Infrastructure;

use App\Kernel\DocumentManagerFactory;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use PHPUnit\Framework\TestCase;

final class MongoVendingMachineRepositoryTest extends TestCase
{
    public function test_it_persists_and_retrieves_a_vending_machine(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        self::assertNull($repository->find($id));

        $repository->save(VendingMachine::create($id));

        $found = $repository->find($id);

        self::assertNotNull($found);
        self::assertTrue($found->id()->equals($id));
    }
}
