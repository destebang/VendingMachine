<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `POST /vending-machines` — provisions a new machine and returns its id.
 */
final class ProvisionVendingMachineController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->commandBus->handle(new ProvisionVendingMachine());

        return new JsonResponse(['id' => $result->id()], JsonResponse::HTTP_CREATED);
    }
}
