<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Console\GetVendingMachineCommand;
use App\VendingMachine\Infrastructure\Console\InsertCoinCommand;
use App\VendingMachine\Infrastructure\Console\ProvisionVendingMachineCommand;
use App\VendingMachine\Infrastructure\Console\ServiceVendingMachineCommand;
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
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 0],
            json_decode($show->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );

        $service = new CommandTester($container->get(ServiceVendingMachineCommand::class));

        self::assertSame(0, $service->execute([
            'id' => $id,
            '--stock' => json_encode(
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                JSON_THROW_ON_ERROR,
            ),
            '--cash-reserve' => json_encode(
                [['denominationInCents' => 100, 'quantity' => 5]],
                JSON_THROW_ON_ERROR,
            ),
        ]));

        self::assertSame(0, $show->execute(['id' => $id]));
        self::assertSame([
            'id' => $id,
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'cashReserve' => [['denominationInCents' => 100, 'quantity' => 5]],
            'insertedFundsInCents' => 0,
        ], json_decode($show->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_the_cli_can_insert_coins_accumulating_the_balance(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($container->get(ProvisionVendingMachineCommand::class));
        $provision->execute([]);

        $id = json_decode($provision->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['id'];

        $insertCoin = new CommandTester($container->get(InsertCoinCommand::class));

        self::assertSame(0, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '25']));
        self::assertSame(
            25,
            json_decode($insertCoin->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['insertedFundsInCents'],
        );

        self::assertSame(0, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '100']));
        self::assertSame(
            125,
            json_decode($insertCoin->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['insertedFundsInCents'],
        );
    }

    public function test_the_cli_rejects_a_denomination_the_machine_does_not_accept(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($container->get(ProvisionVendingMachineCommand::class));
        $provision->execute([]);

        $id = json_decode($provision->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['id'];

        $insertCoin = new CommandTester($container->get(InsertCoinCommand::class));

        self::assertSame(1, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '50']));
        self::assertSame(
            'InvalidDenomination',
            json_decode($insertCoin->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['error'],
        );
    }

    public function test_inserting_into_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $insertCoin = new CommandTester($container->get(InsertCoinCommand::class));

        self::assertSame(1, $insertCoin->execute([
            'id' => VendingMachineId::generate()->toString(),
            '--denomination-in-cents' => '25',
        ]));
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

        $service = new CommandTester($container->get(ServiceVendingMachineCommand::class));

        self::assertSame(1, $service->execute([
            'id' => VendingMachineId::generate()->toString(),
            '--stock' => '[]',
            '--cash-reserve' => '[]',
        ]));
    }
}
