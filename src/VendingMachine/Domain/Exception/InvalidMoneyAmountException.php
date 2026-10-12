<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use InvalidArgumentException;

final class InvalidMoneyAmountException extends InvalidArgumentException
{
    public static function negativeAmount(int $amountInCents): self
    {
        return new self(sprintf('Money amount cannot be negative, got %d cents.', $amountInCents));
    }
}
