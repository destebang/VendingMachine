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

    public function __construct(string $id, int $version = 0)
    {
        $this->id = $id;
        $this->version = $version;
    }
}
