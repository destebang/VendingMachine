<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;

final class VendingMachineTest extends TestCase
{
    public function test_a_newly_created_machine_starts_at_version_zero(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        self::assertSame(0, $machine->version());
    }

    public function test_incrementing_version_bumps_it_by_one(): void
    {
        $machine = VendingMachine::create(VendingMachineId::generate());

        $machine->incrementVersion();
        $machine->incrementVersion();

        self::assertSame(2, $machine->version());
    }

    public function test_reconstitute_restores_identity_and_version(): void
    {
        $id = VendingMachineId::generate();

        $machine = VendingMachine::reconstitute($id, 7);

        self::assertTrue($machine->id()->equals($id));
        self::assertSame(7, $machine->version());
    }
}
