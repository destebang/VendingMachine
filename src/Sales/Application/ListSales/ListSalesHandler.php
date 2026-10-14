<?php

declare(strict_types=1);

namespace App\Sales\Application\ListSales;

use App\Sales\Application\ReadModel\SaleView;
use App\Sales\Domain\Repository\SaleRepository;
use App\Shared\Application\Bus\Attribute\QueryHandler;

/**
 * Serves every registered sale from the write model, projected into the
 * read-side `SaleView`. There is no separate read store yet: at this size the
 * repository is the simplest honest answer.
 */
#[QueryHandler]
final class ListSalesHandler
{
    public function __construct(
        private readonly SaleRepository $sales,
    ) {}

    /**
     * @return list<SaleView>
     */
    public function __invoke(ListSales $query): array
    {
        $views = [];

        foreach ($this->sales->all() as $sale) {
            $views[] = SaleView::fromSale($sale);
        }

        return $views;
    }
}
