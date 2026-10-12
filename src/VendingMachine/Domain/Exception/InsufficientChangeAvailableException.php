<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use App\VendingMachine\Domain\Model\Money;
use RuntimeException;

/**
 * Thrown when a sale would otherwise be valid but the machine cannot make the
 * required change from the coins in its cash reserve. Exact change only: the
 * sale is rejected and every inserted coin is returned to the customer.
 */
final class InsufficientChangeAvailableException extends RuntimeException
{
    public static function forAmount(Money $changeDue): self
    {
        return new self(sprintf(
            'Cannot make %d cents of change from the machine\'s cash reserve.',
            $changeDue->amountInCents(),
        ));
    }
}
