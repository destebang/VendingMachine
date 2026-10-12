<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\SelectItem\SelectItemResult;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * `POST /vending-machines/{id}/selections` — a customer selects a product; the
 * machine vends it and the response carries the vended item, the change handed
 * back and the resulting state. A selection the machine cannot serve is answered
 * with the rejection reason.
 */
final class SelectItemController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            /** @var array{selectorCode?: string} $payload */
            $payload = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);

            $command = SelectItem::fromInput($id, (string) ($payload['selectorCode'] ?? ''));
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            /** @var SelectItemResult $result */
            $result = $this->handle($command);
        } catch (HandlerFailedException $transportException) {
            return $this->errorResponse($transportException);
        }

        return new JsonResponse($result->toArray());
    }

    private function errorResponse(HandlerFailedException $transportException): JsonResponse
    {
        $cause = $transportException->getPrevious() ?? $transportException;

        $error = match (true) {
            $cause instanceof InsufficientFundsException => 'InsufficientFunds',
            $cause instanceof OutOfStockException => 'OutOfStock',
            $cause instanceof InsufficientChangeAvailableException => 'InsufficientChangeAvailable',
            default => null,
        };

        if ($error !== null) {
            return new JsonResponse([
                'error' => $error,
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
