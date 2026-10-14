<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\SelectItem\SelectItemResult;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\InsufficientFundsException;
use App\VendingMachine\Domain\Exception\OutOfStockException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * `POST /vending-machines/{id}/selections` — a customer selects a product; the
 * machine vends it and the response carries the vended item, the change handed
 * back and the resulting state. A selection the machine cannot serve is answered
 * with the rejection reason.
 */
final class SelectItemController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        try {
            /** @var array{selectorCode?: string} $payload */
            $payload = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

            $command = SelectItem::fromInput($id, $payload['selectorCode'] ?? '');
        } catch (Throwable $exception) {
            return new JsonResponse([
                'error' => 'InvalidRequest',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            /** @var SelectItemResult $result */
            $result = $this->commandBus->handle($command);
        } catch (InsufficientFundsException $exception) {
            return $this->rejection('InsufficientFunds', $exception);
        } catch (OutOfStockException $exception) {
            return $this->rejection('OutOfStock', $exception);
        } catch (InsufficientChangeAvailableException $exception) {
            return $this->rejection('InsufficientChangeAvailable', $exception);
        } catch (VendingMachineNotFoundException $exception) {
            return new JsonResponse([
                'error' => 'VendingMachineNotFound',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse($result->toArray());
    }

    private function rejection(string $error, Throwable $exception): JsonResponse
    {
        return new JsonResponse([
            'error' => $error,
            'message' => $exception->getMessage(),
        ], JsonResponse::HTTP_CONFLICT);
    }
}
