<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CLI equivalent of `POST /vending-machines`: provisions a new machine and
 * prints its id as JSON.
 */
#[AsCommand(name: 'app:vending-machine:provision', description: 'Provisions a new vending machine and prints its id.')]
final class ProvisionVendingMachineCommand extends Command
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        parent::__construct();

        $this->messageBus = $messageBus;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->handle(new ProvisionVendingMachine());

        $output->writeln(json_encode(['id' => $result->id()], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
