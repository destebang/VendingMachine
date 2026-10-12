<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\OutOfStockException;

/**
 * A product a vending machine can sell: a selector code (the button a
 * customer presses), a price, and how many units are currently stocked.
 *
 * Deliberately generic — no hardcoded catalogue. New products are created by
 * instantiating this class with a new selector code, not by touching the
 * class itself.
 */
final class Product
{
    private function __construct(
        private readonly string $selectorCode,
        private readonly Money $price,
        private int $stock,
    ) {
    }

    public static function create(string $selectorCode, Money $price, int $stock): self
    {
        return new self($selectorCode, $price, $stock);
    }

    public function selectorCode(): string
    {
        return $this->selectorCode;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function stock(): int
    {
        return $this->stock;
    }

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    public function decreaseStock(): void
    {
        if (!$this->isInStock()) {
            throw OutOfStockException::forProduct($this->selectorCode);
        }

        $this->stock--;
    }

    public function increaseStock(int $units): void
    {
        $this->stock += $units;
    }
}
