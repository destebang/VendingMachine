<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
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
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/service`: declares a machine's
 * true stock, cash reserve and accepted denominations (replace semantics) and
 * prints its state.
 */
#[AsCommand(
    name: 'app:vending-machine:service',
    description: 'Sets a machine\'s stock, cash reserve and accepted denominations (replace semantics).',
)]
final class ServiceVendingMachineCommand extends Command
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
        $this->addOption(
            'accepted-denominations-in-cents',
            null,
            InputOption::VALUE_REQUIRED,
            'JSON list of the accepted denominations in cents, e.g. [5,10,25,100].',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');

        try {
            /** @var list<array{selectorCode: string, priceInCents: int, stock: int}> $stock */
            $stock = json_decode((string) $input->getOption('stock'), associative: true, flags: JSON_THROW_ON_ERROR);

            /** @var list<array{denominationInCents: int, quantity: int}> $cashReserve */
            $cashReserve = json_decode(
                (string) $input->getOption('cash-reserve'),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            /** @var list<int> $acceptedDenominationsInCents */
            $acceptedDenominationsInCents = json_decode(
                (string) $input->getOption('accepted-denominations-in-cents'),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );

            $command = ServiceVendingMachine::fromInput($id, $stock, $cashReserve, $acceptedDenominationsInCents);
        } catch (Throwable $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        try {
            /** @var ServiceVendingMachineResult $result */
            $result = $this->commandBus->handle($command);
        } catch (PendingTransactionInProgressException $exception) {
            $output->writeln(json_encode([
                'error' => 'PendingTransactionInProgress',
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
