<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\ReturnCoin\ReturnCoinResult;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

/**
 * `POST /vending-machines/{id}/return-coin` — a customer takes back every coin
 * inserted so far; the response carries the coins handed back and the reset
 * balance. Returning with nothing inserted is a no-op, not an error.
 */
final class ReturnCoinController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        try {
            $command = ReturnCoin::fromInput($id);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            /** @var ReturnCoinResult $result */
            $result = $this->commandBus->handle($command);
        } catch (VendingMachineNotFoundException $exception) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->toArray());
    }
}
