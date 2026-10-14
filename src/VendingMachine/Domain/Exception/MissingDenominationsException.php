<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use InvalidArgumentException;

final class MissingDenominationsException extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('A machine must accept at least one denomination, got none.');
    }
}
