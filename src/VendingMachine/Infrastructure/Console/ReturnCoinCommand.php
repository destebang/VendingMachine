<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\ReturnCoin\ReturnCoinResult;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/return-coin`: returns every
 * inserted coin and prints the coins handed back plus the reset balance.
 */
#[AsCommand(
    name: 'app:vending-machine:return-coin',
    description: 'Returns every inserted coin and resets the balance.',
)]
final class ReturnCoinCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Vending machine id (UUID).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $command = ReturnCoin::fromInput((string) $input->getArgument('id'));
        } catch (Throwable $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        try {
            /** @var ReturnCoinResult $result */
            $result = $this->commandBus->handle($command);
        } catch (VendingMachineNotFoundException $exception) {
            $output->writeln(json_encode([
                'error' => 'VendingMachineNotFound',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
