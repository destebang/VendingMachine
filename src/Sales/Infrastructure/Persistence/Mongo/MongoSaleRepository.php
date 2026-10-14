<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Persistence\Mongo;

use App\Sales\Domain\Model\Sale;
use App\Sales\Domain\Model\SaleId;
use App\Sales\Domain\Repository\SaleRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * MongoDB-backed implementation of the Sales write model.
 *
 * `save()` inserts one document per sale into the `sales` collection. The
 * collection carries a unique index on `sourceEventId`, so a second write of
 * the same source event fails with a duplicate-key error, which this adapter
 * swallows: delivery is at-least-once, and registering the same message twice
 * is a silent no-op, not an error.
 */
final class MongoSaleRepository implements SaleRepository
{
    /** MongoDB's duplicate-key error code. */
    private const int DUPLICATE_KEY = 11_000;

    /**
     * The index is ensured once per process, on first use, so a fresh database
     * gets the unique index without a separate setup step.
     */
    private bool $indexesEnsured = false;

    public function __construct(
        private readonly DocumentManager $documentManager,
    ) {}

    public function save(Sale $sale): void
    {
        $this->ensureIndexes();

        try {
            $this->collection()->insertOne([
                '_id' => $sale->saleId->toString(),
                'vendingMachineId' => $sale->vendingMachineId,
                'selectorCode' => $sale->selectorCode,
                'priceInCents' => $sale->priceInCents,
                'soldAt' => $sale->soldAt->format(DateTimeInterface::RFC3339),
                'sourceEventId' => $sale->sourceEventId,
            ]);
        } catch (BulkWriteException $exception) {
            if ($exception->getCode() !== self::DUPLICATE_KEY) {
                throw $exception;
            }

            // The source event was already registered: the bus delivers
            // at-least-once, so the same message may arrive again, and the
            // unique index makes the second registration a no-op.
            return;
        }
    }

    public function all(): array
    {
        $this->ensureIndexes();

        /** @var list<array{_id: string, vendingMachineId: string, selectorCode: string, priceInCents: int, soldAt: string, sourceEventId: string}> $documents */
        $documents = iterator_to_array(
            $this->collection()->find([], ['sort' => ['soldAt' => 1, '_id' => 1]]),
            preserve_keys: false,
        );

        $sales = [];
        foreach ($documents as $document) {
            $sales[] = new Sale(
                SaleId::fromString($document['_id']),
                $document['vendingMachineId'],
                $document['selectorCode'],
                (int) $document['priceInCents'],
                new DateTimeImmutable($document['soldAt']),
                $document['sourceEventId'],
            );
        }

        return $sales;
    }

    private function ensureIndexes(): void
    {
        if ($this->indexesEnsured) {
            return;
        }

        $this->documentManager->getSchemaManager()->ensureDocumentIndexes(SaleDocument::class);
        $this->indexesEnsured = true;
    }

    private function collection(): Collection
    {
        return $this->documentManager->getDocumentCollection(SaleDocument::class);
    }
}
