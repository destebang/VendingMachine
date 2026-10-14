<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\HealthCheck;

use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\VendingMachine\Application\ReadModel\VendingMachineStateReadModelRepository;
use App\VendingMachine\Domain\Model\AcceptedDenominations;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;
use Throwable;

/**
 * Proves the write model (MongoDB, via the domain repository port) and the
 * read model (Redis, via the read-model port) are both reachable and can
 * round-trip data, without the application layer knowing which concrete
 * technology sits behind either port.
 */
#[CommandHandler]
final class HealthCheckHandler
{
    public function __construct(
        private readonly VendingMachineRepository $vendingMachineRepository,
        private readonly VendingMachineStateReadModelRepository $readModel,
    ) {}

    public function __invoke(HealthCheck $command): HealthCheckResult
    {
        return new HealthCheckResult(mongoOk: $this->canRoundTripWriteModel(), redisOk: $this->canRoundTripReadModel());
    }

    private function canRoundTripWriteModel(): bool
    {
        try {
            $id = VendingMachineId::generate();
            // A representative coin set: the health check only needs a machine
            // that can round-trip, not any particular configuration.
            $machine = VendingMachine::create($id, AcceptedDenominations::fromCents([5, 10, 25, 100]));
            $this->vendingMachineRepository->save($machine);

            return $this->vendingMachineRepository->find($id) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function canRoundTripReadModel(): bool
    {
        try {
            $marker = bin2hex(random_bytes(8));
            $this->readModel->put($marker, ['ping' => 'pong']);
            $state = $this->readModel->get($marker);

            return ($state['ping'] ?? null) === 'pong';
        } catch (Throwable) {
            return false;
        }
    }
}
