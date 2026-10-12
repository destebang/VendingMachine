<?php

declare(strict_types=1);

namespace App\VendingMachine\Infrastructure\Persistence\Mongo;

use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Domain\Repository\VendingMachineRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * MongoDB-backed implementation of the write-side repository, via Doctrine
 * ODM. Translates between the persistence-agnostic `VendingMachine`
 * aggregate and the Mongo-specific `VendingMachineDocument`.
 */
final class MongoVendingMachineRepository implements VendingMachineRepository
{
    public function __construct(
        private readonly DocumentManager $documentManager,
    ) {
    }

    public function find(VendingMachineId $id): ?VendingMachine
    {
        $document = $this->documentManager->find(VendingMachineDocument::class, $id->toString());

        if (!$document instanceof VendingMachineDocument) {
            return null;
        }

        return VendingMachine::reconstitute($id, $document->version);
    }

    public function save(VendingMachine $vendingMachine): void
    {
        $document = $this->documentManager->find(VendingMachineDocument::class, $vendingMachine->id()->toString());

        if (!$document instanceof VendingMachineDocument) {
            $document = new VendingMachineDocument($vendingMachine->id()->toString(), $vendingMachine->version());
            $this->documentManager->persist($document);
        }

        $this->documentManager->flush();
    }
}
