<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use InvalidArgumentException;

final class InvalidDenominationException extends InvalidArgumentException
{
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
