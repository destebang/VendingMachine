<?php

declare(strict_types=1);

namespace App\Sales\Application\ReadModel;

use App\Sales\Domain\Model\Sale;

/**
 * Read-side projection of a single sale: the flat, serialisable shape served
 * by the query side.
 *
 * It is deliberately decoupled from the domain record, but built from it in
 * exactly one place, so the projection shape never leaks into the handler.
 */
final class SaleView
{
    public function __construct(
        public readonly string $saleId,
        public readonly string $vendingMachineId,
        public readonly string $selectorCode,
        public readonly int $priceInCents,
        public readonly string $soldAt,
        public readonly string $sourceEventId,
    ) {}

    public static function fromSale(Sale $sale): self
    {
        return new self(
            saleId: $sale->saleId->toString(),
            vendingMachineId: $sale->vendingMachineId,
            selectorCode: $sale->selectorCode,
            priceInCents: $sale->priceInCents,
            soldAt: $sale->soldAt->format(\DateTimeInterface::RFC3339),
            sourceEventId: $sale->sourceEventId,
        );
    }

    /**
     * @return array{
     *     saleId: string,
     *     vendingMachineId: string,
     *     selectorCode: string,
     *     priceInCents: int,
     *     soldAt: string,
     *     sourceEventId: string
     * }
     */
    public function toArray(): array
    {
        return [
            'saleId' => $this->saleId,
            'vendingMachineId' => $this->vendingMachineId,
            'selectorCode' => $this->selectorCode,
            'priceInCents' => $this->priceInCents,
            'soldAt' => $this->soldAt,
            'sourceEventId' => $this->sourceEventId,
        ];
    }
}
