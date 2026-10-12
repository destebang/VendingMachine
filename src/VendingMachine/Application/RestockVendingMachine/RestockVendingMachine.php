<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\RestockVendingMachine;

use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Service action: declare the true physical state of a machine's item stock
 * and coin reserve. Semantics are replace, not additive.
 */
final class RestockVendingMachine
{
    /**
     * @param array<string, Product> $stock  selector code => product
     * @param array<int, int>       $change denomination in cents => quantity
     */
    public function __construct(
        public readonly VendingMachineId $vendingMachineId,
        public readonly array $stock,
        public readonly array $change,
    ) {
    }

    /**
     * Builds the command from already-decoded transport input (HTTP JSON body,
     * CLI options), so both driving adapters share the exact same translation
     * instead of duplicating it.
     *
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $change
     */
    public static function fromInput(string $vendingMachineId, array $stock, array $change): self
    {
        $products = [];
        foreach ($stock as $row) {
            $products[$row['selectorCode']] = Product::create(
                $row['selectorCode'],
                Money::fromCents($row['priceInCents']),
                $row['stock'],
            );
        }

        $reserves = [];
        foreach ($change as $row) {
            $reserves[$row['denominationInCents']] = $row['quantity'];
        }

        return new self(VendingMachineId::fromString($vendingMachineId), $products, $reserves);
    }
}
