<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Application\RestockVendingMachine\RestockVendingMachine;
use App\VendingMachine\Application\RestockVendingMachine\RestockVendingMachineResult;
use App\VendingMachine\Domain\Exception\PendingTransactionInProgressException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * `POST /vending-machines/{id}/service` — the technician declares the machine's
 * true item stock and coin reserve (replace semantics) and gets the resulting
 * state back.
 */
final class RestockVendingMachineController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            /** @var array{stock?: list<array{selectorCode: string, priceInCents: int, stock: int}>, change?: list<array{denominationInCents: int, quantity: int}>} $payload */
            $payload = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);

            $command = RestockVendingMachine::fromInput($id, $payload['stock'] ?? [], $payload['change'] ?? []);
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            /** @var RestockVendingMachineResult $result */
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            return $this->errorResponse($transportException);
        }

        return new JsonResponse($result->state->toArray());
    }

    private function errorResponse(HandlerFailedException $transportException): JsonResponse
    {
        $cause = $transportException->getPrevious() ?? $transportException;

        if ($cause instanceof PendingTransactionInProgressException) {
            return new JsonResponse([
                'error' => 'PendingTransactionInProgress',
                'message' => $cause->getMessage(),
            ], JsonResponse::HTTP_CONFLICT);
        }

        if ($cause instanceof VendingMachineNotFoundException) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => $cause->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        throw $transportException;
    }
}
