<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI equivalent of `POST /vending-machines`: provisions a new machine and
 * prints its id as JSON.
 */
#[AsCommand(name: 'app:vending-machine:provision', description: 'Provisions a new vending machine and prints its id.')]
final class ProvisionVendingMachineCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->commandBus->handle(new ProvisionVendingMachine());

        $output->writeln(json_encode(['id' => $result->id()], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
