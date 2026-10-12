<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use RuntimeException;

/**
 * Thrown when a customer selects a product the machine cannot vend because the
 * selector code is not stocked or its last unit has already gone.
 */
final class OutOfStockException extends RuntimeException
{
    public static function forProduct(string $selectorCode): self
    {
        return new self(sprintf('Product "%s" is out of stock.', $selectorCode));
    }
}
