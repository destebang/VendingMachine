<?php

declare(strict_types=1);

namespace App\Tests\Unit\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use PHPUnit\Framework\TestCase;

final class StockTest extends TestCase
{
    public function test_empty_stock_has_no_products(): void
    {
        $stock = Stock::empty();

        self::assertTrue($stock->isEmpty());
        self::assertFalse($stock->has('WATER'));
        self::assertSame(0, $stock->quantityOf('WATER'));
        self::assertSame([], $stock->products());
    }

    public function test_it_indexes_products_by_selector_code(): void
    {
        $stock = Stock::fromProducts([
            Product::create('WATER', Money::fromCents(65), 10),
            Product::create('CHIPS', Money::fromCents(120), 4),
        ]);

        self::assertFalse($stock->isEmpty());
        self::assertTrue($stock->has('WATER'));
        self::assertSame(10, $stock->quantityOf('WATER'));
        self::assertSame(4, $stock->quantityOf('CHIPS'));
        self::assertSame(['WATER', 'CHIPS'], array_keys($stock->products()));
    }

    public function test_a_later_product_for_the_same_selector_code_replaces_the_earlier_one(): void
    {
        $stock = Stock::fromProducts([
            Product::create('WATER', Money::fromCents(65), 10),
            Product::create('WATER', Money::fromCents(65), 2),
        ]);

        self::assertSame(['WATER'], array_keys($stock->products()));
        self::assertSame(2, $stock->quantityOf('WATER'));
    }
}
