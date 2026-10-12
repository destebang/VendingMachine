<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /vending-machines` — provisions a new machine and returns its id.
 */
final class ProvisionVendingMachineController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function __invoke(): JsonResponse
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->handle(new ProvisionVendingMachine());

        return new JsonResponse(['id' => $result->id()], JsonResponse::HTTP_CREATED);
    }
}
