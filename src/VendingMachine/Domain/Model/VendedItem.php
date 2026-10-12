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
final readonly class VendedItem
{
    public function __construct(
        public string $selectorCode,
        public Money $price,
        public ChangeDue $changeDue,
    ) {}
}
