<?php

declare(strict_types=1);

namespace App\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * A sale registered by the Sales context: a machine vended one item, and here
 * is the record of it.
 *
 * A sale is an immutable fact. It is built once from an
 * `item_vended.v1` message and never changes afterwards, so it is readonly and
 * carries only primitives — no domain objects, and nothing that belongs to the
 * vending machine's model. The machine is referenced by its id alone.
 *
 * `sourceEventId` is the integration envelope's `id` (the causing domain
 * event's id). It is the deduplication handle: delivery is at-least-once, so
 * the repository refuses to store the same source event twice.
 */
final readonly class Sale
{
    public function __construct(
        public SaleId $saleId,
        public string $vendingMachineId,
        public string $selectorCode,
        public int $priceInCents,
        public DateTimeImmutable $soldAt,
        public string $sourceEventId,
    ) {}
}
