<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\HealthCheck;

/**
 * Dispatched through the command bus to prove the whole stack - Messenger,
 * MongoDB, Redis - is wired together correctly. Carries no payload: it is a
 * pure infrastructure smoke test, not a business use case.
 */
final class HealthCheck {}
