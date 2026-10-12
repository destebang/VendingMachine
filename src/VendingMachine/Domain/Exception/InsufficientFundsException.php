<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use App\VendingMachine\Domain\Model\Money;
use RuntimeException;

/**
 * Thrown when a customer selects a product whose price is higher than the funds
 * they have inserted. The inserted funds are left untouched, so the customer can
 * add more money and try again.
 */
final class InsufficientFundsException extends RuntimeException
{
    public static function forProduct(string $selectorCode, Money $price, Money $inserted): self
    {
        return new self(sprintf(
            'Product "%s" costs %d cents but only %d cents has been inserted.',
            $selectorCode,
            $price->amountInCents(),
            $inserted->amountInCents(),
        ));
    }
}
