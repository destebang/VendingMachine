<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines`: provisions a new machine
 * configured with the accepted denominations and prints its id as JSON.
 */
#[AsCommand(name: 'app:vending-machine:provision', description: 'Provisions a new vending machine and prints its id.')]
final class ProvisionVendingMachineCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'accepted-denominations-in-cents',
            null,
            InputOption::VALUE_REQUIRED,
            'JSON list of the accepted denominations in cents, e.g. [5,10,25,100].',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            /** @var list<int> $acceptedDenominationsInCents */
            $acceptedDenominationsInCents = json_decode(
                (string) $input->getOption('accepted-denominations-in-cents'),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            $command = ProvisionVendingMachine::fromInput($acceptedDenominationsInCents);
        } catch (Throwable $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        /** @var ProvisionVendingMachineResult $result */
        $result = $this->commandBus->handle($command);

        $output->writeln(json_encode(['id' => $result->id()], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
