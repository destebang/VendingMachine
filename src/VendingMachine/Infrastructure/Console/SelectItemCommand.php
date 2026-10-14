<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\SelectItem\SelectItemResult;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/selections`: selects a product,
 * prints the vended item and the change handed back, or the rejection reason.
 */
#[AsCommand(
    name: 'app:vending-machine:select-item',
    description: 'Selects a product, vending it and returning any change.',
)]
final class SelectItemCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Vending machine id (UUID).');
        $this->addArgument('selector-code', InputArgument::REQUIRED, 'Selector code of the product to vend.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $command = SelectItem::fromInput(
                (string) $input->getArgument('id'),
                (string) $input->getArgument('selector-code'),
            );
        } catch (Throwable $exception) {
            $output->writeln(json_encode([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }

        try {
            /** @var SelectItemResult $result */
            $result = $this->commandBus->handle($command);
        } catch (InsufficientFundsException $exception) {
            return $this->rejection($output, 'InsufficientFunds', $exception);
        } catch (OutOfStockException $exception) {
            return $this->rejection($output, 'OutOfStock', $exception);
        } catch (InsufficientChangeAvailableException $exception) {
            return $this->rejection($output, 'InsufficientChangeAvailable', $exception);
        } catch (VendingMachineNotFoundException $exception) {
            return $this->rejection($output, 'VendingMachineNotFound', $exception);
        }

        $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    private function rejection(OutputInterface $output, string $error, Throwable $exception): int
    {
        $output->writeln(json_encode([
            'error' => $error,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR));

        return Command::FAILURE;
    }
}
