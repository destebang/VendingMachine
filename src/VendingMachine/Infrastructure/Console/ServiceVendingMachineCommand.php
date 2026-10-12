<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachineResult;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
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

/**
 * CLI equivalent of `POST /vending-machines/{id}/service`: declares a machine's
 * true stock and cash reserve (replace semantics) and prints its state.
 */
#[AsCommand(name: 'app:vending-machine:service', description: 'Sets a machine\'s stock and cash reserve (replace semantics).')]
final class ServiceVendingMachineCommand extends Command
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
            'stock',
            null,
            InputOption::VALUE_REQUIRED,
            'JSON list of {"selectorCode","priceInCents","stock"}.',
            '[]',
        );
        $this->addOption(
            'cash-reserve',
            null,
            InputOption::VALUE_REQUIRED,
            'JSON list of {"denominationInCents","quantity"}.',
            '[]',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');

        try {
            /** @var list<array{selectorCode: string, priceInCents: int, stock: int}> $stock */
            $stock = json_decode((string) $input->getOption('stock'), true, flags: JSON_THROW_ON_ERROR);

            /** @var list<array{denominationInCents: int, quantity: int}> $cashReserve */
            $cashReserve = json_decode((string) $input->getOption('cash-reserve'), true, flags: JSON_THROW_ON_ERROR);

            $command = ServiceVendingMachine::fromInput($id, $stock, $cashReserve);
        } catch (\Throwable $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        try {
            /** @var ServiceVendingMachineResult $result */
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            $cause = $transportException->getPrevious() ?? $transportException;

            if ($cause instanceof PendingTransactionInProgressException) {
                $output->writeln(json_encode([
                    'error' => 'PendingTransactionInProgress',
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
