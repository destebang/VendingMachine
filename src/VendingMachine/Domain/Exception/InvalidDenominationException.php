<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use InvalidArgumentException;

final class InvalidDenominationException extends InvalidArgumentException
{
    public static function notPositive(int $amountInCents): self
    {
        return new self(sprintf('A denomination must be a positive amount of cents, got %d.', $amountInCents));
    }

    /**
     * @param list<int> $allowedAmountsInCents
     */
    public static function notAllowed(int $amountInCents, array $allowedAmountsInCents): self
    {
        return new self(sprintf(
            '%d cents is not an allowed denomination. Allowed denominations are: %s.',
            $amountInCents,
            implode(', ', $allowedAmountsInCents),
        ));
    }
}
