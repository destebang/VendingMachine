<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\QueryBus;
use App\VendingMachine\Application\GetVendingMachineState\GetVendingMachineState;
use App\VendingMachine\Application\ReadModel\MachineStateView;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /vending-machines/{id}` — returns the current projected state (stock,
 * cash reserve, inserted funds) from the Redis read model, asking through the
 * `QueryBus` port rather than the write-side command bus.
 */
final class GetVendingMachineController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        /** @var MachineStateView|null $state */
        $state = $this->queryBus->ask(new GetVendingMachineState($id));

        if ($state === null) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => sprintf('Vending machine "%s" was not found.', $id),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($state->toArray());
    }
}
