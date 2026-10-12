<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Console\GetVendingMachineCommand;
use App\VendingMachine\Infrastructure\Console\ProvisionVendingMachineCommand;
use App\VendingMachine\Infrastructure\Console\RestockVendingMachineCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class VendingMachineCommandTest extends TestCase
{
    public function test_the_cli_can_provision_service_and_show_a_machine(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($container->get(ProvisionVendingMachineCommand::class));

        self::assertSame(0, $provision->execute([]));

        $id = json_decode($provision->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['id'];

        $show = new CommandTester($container->get(GetVendingMachineCommand::class));

        self::assertSame(0, $show->execute(['id' => $id]));
        self::assertSame(
            ['id' => $id, 'stock' => [], 'change' => [], 'balanceInCents' => 0],
            json_decode($show->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );

        $service = new CommandTester($container->get(RestockVendingMachineCommand::class));

        self::assertSame(0, $service->execute([
            'id' => $id,
            '--stock' => json_encode(
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                JSON_THROW_ON_ERROR,
            ),
            '--change' => json_encode(
                [['denominationInCents' => 100, 'quantity' => 5]],
                JSON_THROW_ON_ERROR,
            ),
        ]));

        self::assertSame(0, $show->execute(['id' => $id]));
        self::assertSame([
            'id' => $id,
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'change' => [['denominationInCents' => 100, 'quantity' => 5]],
            'balanceInCents' => 0,
        ], json_decode($show->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_showing_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $show = new CommandTester($container->get(GetVendingMachineCommand::class));

        self::assertSame(1, $show->execute(['id' => VendingMachineId::generate()->toString()]));
    }

    public function test_servicing_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $service = new CommandTester($container->get(RestockVendingMachineCommand::class));

        self::assertSame(1, $service->execute([
            'id' => VendingMachineId::generate()->toString(),
            '--stock' => '[]',
            '--change' => '[]',
        ]));
    }
}
