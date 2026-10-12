<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InsufficientStockException;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function test_it_decreases_stock(): void
    {
        $product = Product::create('WATER', Money::fromCents(65), stock: 2);

        $product->decreaseStock();

        self::assertSame(1, $product->stock());
        self::assertTrue($product->isInStock());
    }

    public function test_it_cannot_decrease_stock_below_zero(): void
    {
        $product = Product::create('WATER', Money::fromCents(65), stock: 0);

        $this->expectException(InsufficientStockException::class);

        $product->decreaseStock();
    }

    public function test_it_increases_stock(): void
    {
        $product = Product::create('WATER', Money::fromCents(65), stock: 0);

        $product->increaseStock(10);

        self::assertSame(10, $product->stock());
    }
}
