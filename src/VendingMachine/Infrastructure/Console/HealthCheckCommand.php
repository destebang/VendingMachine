<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Console;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\HealthCheck\HealthCheck;
use App\VendingMachine\Application\HealthCheck\HealthCheckResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI equivalent of `GET /health`, dispatching the same command through the
 * same bus, so both entry points exercise identical application logic.
 */
#[AsCommand(name: 'app:health-check', description: 'Checks that MongoDB and Redis are reachable.')]
final class HealthCheckCommand extends Command
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var HealthCheckResult $result */
        $result = $this->commandBus->handle(new HealthCheck());

        $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return $result->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }
}
