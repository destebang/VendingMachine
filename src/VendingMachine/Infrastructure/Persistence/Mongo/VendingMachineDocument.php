<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Persistence\Mongo;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * Doctrine ODM document for a vending machine. Deliberately kept separate
 * from the `VendingMachine` domain aggregate: this class only exists to
 * describe how the write model is stored in MongoDB, and carries no
 * business behaviour. `VendingMachineRepository` is the only place that
 * translates between the two.
 */
#[ODM\Document(collection: 'vending_machines')]
class VendingMachineDocument
{
    #[ODM\Id(strategy: 'none', type: 'string')]
    public string $id;

    #[ODM\Field(type: 'int')]
    #[ODM\Version]
    public int $version = 0;

    #[ODM\Field(type: 'int')]
    public int $insertedFundsInCents = 0;

    /**
     * Stock as a list of flat rows: selector code, price in cents and units
     * loaded. Stored as a list (not a map) so MongoDB keeps it ordered and
     * round-trips it as a plain array.
     *
     * @var list<array{selectorCode: string, priceInCents: int, stock: int}>
     */
    #[ODM\Field(type: 'raw')]
    public array $stock = [];

    /**
     * Cash reserve as a list of flat rows: denomination in cents and number of
     * units held.
     *
     * @var list<array{denominationInCents: int, quantity: int}>
     */
    #[ODM\Field(type: 'raw')]
    public array $cashReserve = [];

    public function __construct(string $id, int $version = 0)
    {
        $this->id = $id;
        $this->version = $version;
    }
}
