<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * CLI equivalent of `POST /vending-machines/{id}/selections`: selects a product,
 * prints the vended item and the change handed back, or the rejection reason.
 */
#[AsCommand(name: 'app:vending-machine:select-item', description: 'Selects a product, vending it and returning any change.')]
final class SelectItemCommand extends Command
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
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            $cause = $transportException->getPrevious() ?? $transportException;

            $error = match (true) {
                $cause instanceof InsufficientFundsException => 'InsufficientFunds',
                $cause instanceof OutOfStockException => 'OutOfStock',
                $cause instanceof InsufficientChangeAvailableException => 'InsufficientChangeAvailable',
                $cause instanceof VendingMachineNotFoundException => 'VendingMachineNotFound',
                default => null,
            };

            if ($error !== null) {
                $output->writeln(json_encode([
                    'error' => $error,
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
