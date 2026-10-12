<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\VendingMachine\Application\GetVendingMachineState\GetVendingMachineState;
use App\VendingMachine\Domain\Read\MachineStateView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CLI equivalent of `GET /vending-machines/{id}`: prints the machine's current
 * projected state (stock, cash reserve, inserted funds) from the read model.
 */
#[AsCommand(name: 'app:vending-machine:show', description: 'Prints a machine\'s current state from the read model.')]
final class GetVendingMachineCommand extends Command
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        parent::__construct();

        $this->messageBus = $messageBus;
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Vending machine id (UUID).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var MachineStateView|null $state */
        $state = $this->handle(new GetVendingMachineState((string) $input->getArgument('id')));

        if ($state === null) {
            $output->writeln(json_encode(['error' => 'VendingMachineNotFound'], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        $output->writeln(json_encode($state->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
