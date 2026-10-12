<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Console\GetVendingMachineCommand;
use App\VendingMachine\Infrastructure\Console\InsertCoinCommand;
use App\VendingMachine\Infrastructure\Console\ProvisionVendingMachineCommand;
use App\VendingMachine\Infrastructure\Console\ReturnCoinCommand;
use App\VendingMachine\Infrastructure\Console\SelectItemCommand;
use App\VendingMachine\Infrastructure\Console\ServiceVendingMachineCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class VendingMachineCommandTest extends TestCase
{
    public function test_the_cli_can_provision_service_and_show_a_machine(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($this->command($container, ProvisionVendingMachineCommand::class));

        self::assertSame(0, $provision->execute([]));

        $id = $this->decode($provision->getDisplay())['id'];

        $show = new CommandTester($this->command($container, GetVendingMachineCommand::class));

        self::assertSame(0, $show->execute(['id' => $id]));
        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 0],
            $this->decode($show->getDisplay()),
        );

        $service = new CommandTester($this->command($container, ServiceVendingMachineCommand::class));

        self::assertSame(0, $service->execute([
            'id' => $id,
            '--stock' => json_encode([[
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'stock' => 10,
            ]], JSON_THROW_ON_ERROR),
            '--cash-reserve' => json_encode([['denominationInCents' => 100, 'quantity' => 5]], JSON_THROW_ON_ERROR),
        ]));

        self::assertSame(0, $show->execute(['id' => $id]));
        self::assertSame(
            [
                'id' => $id,
                'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                'cashReserve' => [['denominationInCents' => 100, 'quantity' => 5]],
                'insertedFundsInCents' => 0,
            ],
            $this->decode($show->getDisplay()),
        );
    }

    public function test_the_cli_can_insert_coins_accumulating_the_balance(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($this->command($container, ProvisionVendingMachineCommand::class));
        $provision->execute([]);

        $id = $this->decode($provision->getDisplay())['id'];

        $insertCoin = new CommandTester($this->command($container, InsertCoinCommand::class));

        self::assertSame(0, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '25']));
        self::assertSame(25, $this->decode($insertCoin->getDisplay())['insertedFundsInCents']);

        self::assertSame(0, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '100']));
        self::assertSame(125, $this->decode($insertCoin->getDisplay())['insertedFundsInCents']);
    }

    public function test_the_cli_rejects_a_denomination_the_machine_does_not_accept(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($this->command($container, ProvisionVendingMachineCommand::class));
        $provision->execute([]);

        $id = $this->decode($provision->getDisplay())['id'];

        $insertCoin = new CommandTester($this->command($container, InsertCoinCommand::class));

        self::assertSame(1, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '50']));
        self::assertSame('InvalidDenomination', $this->decode($insertCoin->getDisplay())['error']);
    }

    public function test_inserting_into_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $insertCoin = new CommandTester($this->command($container, InsertCoinCommand::class));

        self::assertSame(1, $insertCoin->execute([
            'id' => VendingMachineId::generate()->toString(),
            '--denomination-in-cents' => '25',
        ]));
    }

    public function test_the_cli_can_return_inserted_coins(): void
    {
        $container = ContainerFactory::create();

        $provision = new CommandTester($this->command($container, ProvisionVendingMachineCommand::class));
        $provision->execute([]);

        $id = $this->decode($provision->getDisplay())['id'];

        $insertCoin = new CommandTester($this->command($container, InsertCoinCommand::class));
        $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '10']);
        $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '10']);

        $returnCoin = new CommandTester($this->command($container, ReturnCoinCommand::class));

        self::assertSame(0, $returnCoin->execute(['id' => $id]));
        self::assertSame(
            [
                'id' => $id,
                'returnedCoins' => [10, 10],
                'insertedFundsInCents' => 0,
            ],
            $this->decode($returnCoin->getDisplay()),
        );
    }

    public function test_returning_on_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $returnCoin = new CommandTester($this->command($container, ReturnCoinCommand::class));

        self::assertSame(1, $returnCoin->execute(['id' => VendingMachineId::generate()->toString()]));
    }

    public function test_the_cli_can_vend_an_item_and_return_change(): void
    {
        $container = ContainerFactory::create();
        $id = $this->servicedMachine($container);

        $insertCoin = new CommandTester($this->command($container, InsertCoinCommand::class));
        self::assertSame(0, $insertCoin->execute(['id' => $id, '--denomination-in-cents' => '100']));

        $select = new CommandTester($this->command($container, SelectItemCommand::class));

        self::assertSame(0, $select->execute(['id' => $id, 'selector-code' => 'WATER']));
        self::assertSame(
            [
                'id' => $id,
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'change' => [25, 10],
                'insertedFundsInCents' => 0,
            ],
            $this->decode($select->getDisplay()),
        );
    }

    public function test_the_cli_reports_a_rejected_selection(): void
    {
        $container = ContainerFactory::create();
        $id = $this->servicedMachine($container);

        $select = new CommandTester($this->command($container, SelectItemCommand::class));

        self::assertSame(1, $select->execute(['id' => $id, 'selector-code' => 'WATER']));
        self::assertSame('InsufficientFunds', $this->decode($select->getDisplay())['error']);
    }

    public function test_selecting_on_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $select = new CommandTester($this->command($container, SelectItemCommand::class));

        self::assertSame(1, $select->execute([
            'id' => VendingMachineId::generate()->toString(),
            'selector-code' => 'WATER',
        ]));
    }

    private function servicedMachine(ContainerInterface $container): string
    {
        $provision = new CommandTester($this->command($container, ProvisionVendingMachineCommand::class));
        $provision->execute([]);

        $id = $this->decode($provision->getDisplay())['id'];

        $service = new CommandTester($this->command($container, ServiceVendingMachineCommand::class));
        $service->execute([
            'id' => $id,
            '--stock' => json_encode([[
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'stock' => 10,
            ]], JSON_THROW_ON_ERROR),
            '--cash-reserve' => json_encode([
                ['denominationInCents' => 25, 'quantity' => 40],
                ['denominationInCents' => 10, 'quantity' => 40],
            ], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    public function test_showing_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $show = new CommandTester($this->command($container, GetVendingMachineCommand::class));

        self::assertSame(1, $show->execute(['id' => VendingMachineId::generate()->toString()]));
    }

    public function test_servicing_an_unknown_machine_fails(): void
    {
        $container = ContainerFactory::create();

        $service = new CommandTester($this->command($container, ServiceVendingMachineCommand::class));

        self::assertSame(1, $service->execute([
            'id' => VendingMachineId::generate()->toString(),
            '--stock' => '[]',
            '--cash-reserve' => '[]',
        ]));
    }

    private function command(ContainerInterface $container, string $commandClass): Command
    {
        /** @var Command $command */
        $command = $container->get($commandClass);
        self::assertInstanceOf(Command::class, $command);

        return $command;
    }

    /**
     * The union of every key the CLI can print, so a test can read any of them
     * without re-checking. A test never reads a key the output omits.
     *
     * @return array{
     *     id: string,
     *     selectorCode: string,
     *     priceInCents: int,
     *     change: list<int>,
     *     returnedCoins: list<int>,
     *     insertedFundsInCents: int,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     error: string
     * }
     */
    private function decode(string $json): array
    {
        /** @var array{
         *     id: string,
         *     selectorCode: string,
         *     priceInCents: int,
         *     change: list<int>,
         *     returnedCoins: list<int>,
         *     insertedFundsInCents: int,
         *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
         *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
         *     error: string
         * } $payload */
        $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
