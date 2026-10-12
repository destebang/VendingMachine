<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use RuntimeException;

final class InsufficientStockException extends RuntimeException
{
    public static function forProduct(string $selectorCode): self
    {
        return new self(sprintf('Product "%s" is out of stock.', $selectorCode));
    }
}
