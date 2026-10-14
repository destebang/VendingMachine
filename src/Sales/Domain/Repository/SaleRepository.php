<?php

declare(strict_types=1);

namespace App\Sales\Domain\Repository;

use App\Sales\Domain\Model\Sale;

/**
 * Port for the Sales write model.
 *
 * The consumer registers a sale through this port; the MongoDB adapter is the
 * only place that knows how a sale is stored. `save()` is idempotent on
 * `sourceEventId`, so the at-least-once delivery of the integration bus cannot
 * register the same sale twice.
 */
interface SaleRepository
{
    public function save(Sale $sale): void;

    /**
     * Every registered sale, oldest first.
     *
     * @return list<Sale>
     */
    public function all(): array;
}
