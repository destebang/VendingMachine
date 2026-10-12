<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Http;

use App\VendingMachine\Application\HealthCheck\HealthCheck;
use App\VendingMachine\Application\HealthCheck\HealthCheckResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `GET /health` — dispatches the `HealthCheck` command through the same bus
 * the CLI uses and reports whether MongoDB and Redis are reachable.
 */
final class HealthCheckController
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function __invoke(): JsonResponse
    {
        /** @var HealthCheckResult $result */
        $result = $this->handle(new HealthCheck());

        return new JsonResponse($result->toArray(), $result->isHealthy() ? 200 : 503);
    }
}
