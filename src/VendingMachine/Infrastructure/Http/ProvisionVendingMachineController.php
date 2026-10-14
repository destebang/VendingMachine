<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * `POST /vending-machines` — provisions a new machine configured with the
 * accepted denominations in the JSON body, and returns its id.
 */
final class ProvisionVendingMachineController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            /** @var array{acceptedDenominationsInCents?: list<int>} $payload */
            $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

            $command = ProvisionVendingMachine::fromInput($payload['acceptedDenominationsInCents'] ?? []);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var ProvisionVendingMachineResult $result */
        $result = $this->commandBus->handle($command);

        return new JsonResponse(['id' => $result->id()], JsonResponse::HTTP_CREATED);
    }
}
