<?php

declare(strict_types=1);

namespace App\Sales\Infrastructure\Http;

use App\Sales\Application\ListSales\ListSales;
use App\Sales\Application\ReadModel\SaleView;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * `GET /sales` — lists every registered sale, oldest first, asking through the
 * `QueryBus` port rather than the write-side command bus. A demo read of what
 * the Sales context has consumed from the published language.
 */
final class ListSalesController
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(): JsonResponse
    {
        /** @var list<SaleView> $sales */
        $sales = $this->queryBus->ask(new ListSales());

        return new JsonResponse(array_map(static fn(SaleView $sale): array => $sale->toArray(), $sales));
    }
}
