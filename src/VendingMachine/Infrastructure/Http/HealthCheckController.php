<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\Shared\Application\Bus\CommandBus;
use App\VendingMachine\Application\HealthCheck\HealthCheck;
use App\VendingMachine\Application\HealthCheck\HealthCheckResult;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /health` — dispatches the `HealthCheck` command through the same bus
 * the CLI uses and reports whether MongoDB and Redis are reachable.
 */
final class HealthCheckController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var HealthCheckResult $result */
        $result = $this->commandBus->handle(new HealthCheck());

        return new JsonResponse($result->toArray(), $result->isHealthy() ? 200 : 503);
    }
}
