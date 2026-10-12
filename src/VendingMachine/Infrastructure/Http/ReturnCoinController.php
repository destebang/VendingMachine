<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\ReturnCoin\ReturnCoin;
use App\VendingMachine\Application\ReturnCoin\ReturnCoinResult;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * `POST /vending-machines/{id}/return-coin` — a customer takes back every coin
 * inserted so far; the response carries the coins handed back and the reset
 * balance. Returning with nothing inserted is a no-op, not an error.
 */
final class ReturnCoinController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

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
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            $cause = $transportException->getPrevious() ?? $transportException;

            if ($cause instanceof VendingMachineNotFoundException) {
                return new JsonResponse([
                    'error' => 'VendingMachineNotFound',
                    'message' => $cause->getMessage(),
                ], JsonResponse::HTTP_NOT_FOUND);
            }

            throw $transportException;
        }

        return new JsonResponse($result->toArray());
    }
}
