<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\GetVendingMachineState\GetVendingMachineState;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `GET /vending-machines/{id}` — returns the current projected state (stock,
 * change, balance) from the Redis read model.
 */
final class GetVendingMachineController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function __invoke(string $id): JsonResponse
    {
        /** @var MachineStateView|null $state */
        $state = $this->handle(new GetVendingMachineState($id));

        if ($state === null) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => sprintf('Vending machine "%s" was not found.', $id),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($state->toArray());
    }
}
