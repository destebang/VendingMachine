<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\InsertCoin\InsertCoinResult;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/coins`: inserts a coin into a
 * machine and prints the resulting state, whose `insertedFundsInCents` is the
 * updated balance.
 */
#[AsCommand(
    name: 'app:vending-machine:insert-coin',
    description: 'Inserts a coin into a machine and prints its state.',
)]
final class InsertCoinCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Vending machine id (UUID).');
        $this->addOption(
            'denomination-in-cents',
            null,
            InputOption::VALUE_REQUIRED,
            'Coin denomination to insert, in cents (accepted: 5, 10, 25, 100).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');

        try {
            $command = InsertCoin::fromInput($id, (int) $input->getOption('denomination-in-cents'));
        } catch (Throwable $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        try {
            /** @var InsertCoinResult $result */
            $result = $this->commandBus->handle($command);
        } catch (InvalidDenominationException $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidDenomination',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        } catch (VendingMachineNotFoundException $exception) {
            $output->writeln(json_encode([
                'error' => 'VendingMachineNotFound',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        $output->writeln(json_encode($result->state->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
