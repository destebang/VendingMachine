<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * The outcome of a successful selection: the product a machine dispensed and the
 * change it handed back.
 *
 * Returned by `VendingMachine::selectItem()` so the application layer can report
 * both without reading the aggregate's fields.
 */
final class VendedItem
{
    public function __construct(
        public readonly string $selectorCode,
        public readonly Money $price,
        public readonly ChangeDue $changeDue,
    ) {
    }
}
