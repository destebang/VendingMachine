<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\ReturnCoin\ReturnCoinResult;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/return-coin`: returns every
 * inserted coin and prints the coins handed back plus the reset balance.
 */
#[AsCommand(name: 'app:vending-machine:return-coin', description: 'Returns every inserted coin and resets the balance.')]
final class ReturnCoinCommand extends Command
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
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            $cause = $transportException->getPrevious() ?? $transportException;

            if ($cause instanceof VendingMachineNotFoundException) {
                $output->writeln(json_encode([
                    'error' => 'VendingMachineNotFound',
                    'message' => $cause->getMessage(),
                ], JSON_THROW_ON_ERROR));

                return Command::FAILURE;
            }

            throw $transportException;
        }

        $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
