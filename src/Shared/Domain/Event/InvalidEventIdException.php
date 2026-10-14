<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use InvalidArgumentException;

/**
 * Thrown when a string cannot be read as a domain event identity. The id is a
 * UUIDv7, so anything that is not one is rejected before it can reach an event
 * store, outbox or bus.
 */
final class InvalidEventIdException extends InvalidArgumentException
{
    public static function notAUuidV7(string $id): self
    {
        return new self(sprintf('"%s" is not a valid UUIDv7.', $id));
    }
}
