<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\QueryBus;
use App\VendingMachine\Application\GetVendingMachineState\GetVendingMachineState;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI equivalent of `GET /vending-machines/{id}`: prints the machine's current
 * projected state (stock, cash reserve, inserted funds) from the read model,
 * asking through the `QueryBus` port rather than the write-side command bus.
 */
#[AsCommand(name: 'app:vending-machine:show', description: 'Prints a machine\'s current state from the read model.')]
final class GetVendingMachineCommand extends Command
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Vending machine id (UUID).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var MachineStateView|null $state */
        $state = $this->queryBus->ask(new GetVendingMachineState((string) $input->getArgument('id')));

        if ($state === null) {
            $output->writeln(json_encode(['error' => 'VendingMachineNotFound'], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        $output->writeln(json_encode($state->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
