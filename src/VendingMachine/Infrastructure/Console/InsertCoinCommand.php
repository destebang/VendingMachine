<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/coins`: inserts a coin into a
 * machine and prints the resulting state, whose `insertedFundsInCents` is the
 * updated balance.
 */
#[AsCommand(name: 'app:vending-machine:insert-coin', description: 'Inserts a coin into a machine and prints its state.')]
final class InsertCoinCommand extends Command
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
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            $cause = $transportException->getPrevious() ?? $transportException;

            if ($cause instanceof InvalidDenominationException) {
                $output->writeln(json_encode([
                    'error' => 'InvalidDenomination',
                    'message' => $cause->getMessage(),
                ], JSON_THROW_ON_ERROR));

                return Command::FAILURE;
            }

            if ($cause instanceof VendingMachineNotFoundException) {
                $output->writeln(json_encode([
                    'error' => 'VendingMachineNotFound',
                    'message' => $cause->getMessage(),
                ], JSON_THROW_ON_ERROR));

                return Command::FAILURE;
            }

            throw $transportException;
        }

        $output->writeln(json_encode($result->state->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
