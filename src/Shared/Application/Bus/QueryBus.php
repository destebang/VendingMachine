<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Framework-free port for the read side: asks a query and returns its result.
 *
 * The driving adapters (HTTP controllers, console commands) depend on this
 * interface, never on the concrete bus, so the read side can move onto its own
 * transport without the adapters — or the application layer — knowing.
 */
interface QueryBus
{
    public function ask(object $query): mixed;
}
