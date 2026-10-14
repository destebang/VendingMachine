<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus;

/**
 * Framework-free port for the write side: handles a command and returns its
 * result.
 *
 * The driving adapters (HTTP controllers, console commands) depend on this
 * interface, never on the concrete bus, so the write side is wired like the
 * read side and the framework stays out of the adapters.
 */
interface CommandBus
{
    public function handle(object $command): mixed;
}
