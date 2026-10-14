<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject\Exception;

use InvalidArgumentException;

/**
 * Thrown when a string cannot be read as a UUIDv7 identity. Shared by every
 * id built on `Uuid`, so anything that is not a canonical UUIDv7 is rejected
 * before it can reach a repository.
 */
final class InvalidUuidException extends InvalidArgumentException
{
    public static function notAUuidV7(string $id): self
    {
        return new self(sprintf('"%s" is not a valid UUIDv7.', $id));
    }
}
