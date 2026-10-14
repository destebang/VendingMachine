<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachineResult;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * `POST /vending-machines/{id}/service` — the technician declares the machine's
 * true stock, cash reserve and accepted denominations (replace semantics) and
 * gets the resulting state back.
 */
final class ServiceVendingMachineController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            /** @var array{stock?: list<array{selectorCode: string, priceInCents: int, stock: int}>, cashReserve?: list<array{denominationInCents: int, quantity: int}>, acceptedDenominationsInCents?: list<int>} $payload */
            $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

            $command = ServiceVendingMachine::fromInput(
                $id,
                $payload['stock'] ?? [],
                $payload['cashReserve'] ?? [],
                $payload['acceptedDenominationsInCents'] ?? [],
            );
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            /** @var ServiceVendingMachineResult $result */
            $result = $this->commandBus->handle($command);
        } catch (PendingTransactionInProgressException $exception) {
            return new JsonResponse([
                'error' => 'PendingTransactionInProgress',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_CONFLICT);
        } catch (VendingMachineNotFoundException $exception) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->state->toArray());
    }
}
