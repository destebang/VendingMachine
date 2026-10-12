<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * The products currently loaded in a machine, keyed by selector code.
 *
 * A domain collection instead of a raw array, so the rule that a selector code
 * identifies exactly one loaded product lives in a single place. The products
 * it holds are the `Product` entities themselves.
 */
final class Stock
{
    /**
     * @param array<string, Product> $products
     */
    private function __construct(
        private readonly array $products,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<Product> $products
     */
    public static function fromProducts(array $products): self
    {
        $bySelectorCode = [];
        foreach ($products as $product) {
            $bySelectorCode[$product->selectorCode()] = $product;
        }

        return new self($bySelectorCode);
    }

    public function isEmpty(): bool
    {
        return $this->products === [];
    }

    public function has(string $selectorCode): bool
    {
        return isset($this->products[$selectorCode]);
    }

    public function quantityOf(string $selectorCode): int
    {
        return ($this->products[$selectorCode] ?? null)?->stock() ?? 0;
    }

    /**
     * @return array<string, Product>
     */
    public function products(): array
    {
        return $this->products;
    }
}
