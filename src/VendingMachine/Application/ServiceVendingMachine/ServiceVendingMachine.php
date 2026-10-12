<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ServiceVendingMachine;

use App\VendingMachine\Domain\Model\CashReserve;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\Product;
use App\VendingMachine\Domain\Model\Stock;
use App\VendingMachine\Domain\Model\VendingMachineId;

/**
 * Service action: declare the true physical state of a machine's stock and cash
 * reserve. Semantics are replace, not additive.
 */
final class ServiceVendingMachine
{
    public function __construct(
        public readonly VendingMachineId $vendingMachineId,
        public readonly Stock $stock,
        public readonly CashReserve $cashReserve,
    ) {
    }

    /**
     * Builds the command from already-decoded transport input (HTTP JSON body,
     * CLI options), so both driving adapters share the exact same translation
     * instead of duplicating it.
     *
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $cashReserve
     */
    public static function fromInput(string $vendingMachineId, array $stock, array $cashReserve): self
    {
        $products = [];
        foreach ($stock as $row) {
            $products[] = Product::create(
                $row['selectorCode'],
                Money::fromCents($row['priceInCents']),
                $row['stock'],
            );
        }

        $counts = [];
        foreach ($cashReserve as $row) {
            $counts[$row['denominationInCents']] = $row['quantity'];
        }

        return new self(
            VendingMachineId::fromString($vendingMachineId),
            Stock::fromProducts($products),
            CashReserve::fromCounts($counts),
        );
    }
}
