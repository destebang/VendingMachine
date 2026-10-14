<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Persistence\Mongo;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * Doctrine ODM document for a sale. Deliberately kept separate from the
 * `Sale` domain record: this class only describes how a sale is stored in
 * MongoDB, and carries no behaviour. `MongoSaleRepository` is the only place
 * that translates between the two.
 *
 * The unique index on `sourceEventId` is the deduplication guarantee: the
 * integration bus delivers at-least-once, so the same source event may arrive
 * twice, and the database refuses the second write.
 */
#[ODM\Document(collection: 'sales')]
#[ODM\Index(keys: ['sourceEventId' => 'asc'], options: ['unique' => true])]
final class SaleDocument
{
    #[ODM\Id(strategy: 'none', type: 'string')]
    public string $id;

    #[ODM\Field(type: 'string')]
    public string $vendingMachineId;

    #[ODM\Field(type: 'string')]
    public string $selectorCode;

    #[ODM\Field(type: 'int')]
    public int $priceInCents;

    #[ODM\Field(type: 'string')]
    public string $soldAt;

    #[ODM\Field(type: 'string')]
    public string $sourceEventId;
}
