<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\InsertCoin\InsertCoinResult;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * `POST /vending-machines/{id}/coins` — a customer inserts one coin; the machine
 * records it and the response carries the updated state, whose
 * `insertedFundsInCents` is the new balance.
 */
final class InsertCoinController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            /** @var array{denominationInCents?: int} $payload */
            $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

            $command = InsertCoin::fromInput($id, (int) ($payload['denominationInCents'] ?? 0));
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            /** @var InsertCoinResult $result */
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            return $this->errorResponse($transportException);
        }

        return new JsonResponse($result->toArray());
    }

    private function errorResponse(HandlerFailedException $transportException): JsonResponse
    {
        $cause = $transportException->getPrevious() ?? $transportException;

        if ($cause instanceof InvalidDenominationException) {
            return new JsonResponse([
                'error' => 'InvalidDenomination',
                'message' => $cause->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
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
