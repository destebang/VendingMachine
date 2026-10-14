<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\InsertCoin\InsertCoinResult;
use App\VendingMachine\Domain\Exception\InvalidDenominationException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * `POST /vending-machines/{id}/coins` — a customer inserts one coin; the machine
 * records it and the response carries the updated state, whose
 * `insertedFundsInCents` is the new balance.
 */
final class InsertCoinController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

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
            $result = $this->commandBus->handle($command);
        } catch (InvalidDenominationException $exception) {
            return new JsonResponse([
                'error' => 'InvalidDenomination',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (VendingMachineNotFoundException $exception) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->state->toArray());
    }
}
