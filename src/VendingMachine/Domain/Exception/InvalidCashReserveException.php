<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use InvalidArgumentException;

final class InvalidCashReserveException extends InvalidArgumentException
{
    public static function nonPositiveDenomination(int $denominationInCents): self
    {
        return new self(sprintf(
            'A denomination must be a positive amount of cents, got %d.',
            $denominationInCents,
        ));
    }

    public static function negativeQuantity(int $denominationInCents, int $quantity): self
    {
        return new self(sprintf(
            'The quantity held for the %d cent denomination cannot be negative, got %d.',
            $denominationInCents,
            $quantity,
        ));
    }
}
