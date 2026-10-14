<?php

declare(strict_types=1);

namespace App\Sales\Application\RegisterSale;

use App\Sales\Domain\Model\Sale;
use App\Sales\Domain\Model\SaleId;
use App\Sales\Domain\Repository\SaleRepository;
use App\Shared\Application\Bus\Attribute\CommandHandler;

/**
 * Mints the sale's identity and hands the immutable record to the repository.
 *
 * The repository is idempotent on `sourceEventId`, so re-delivering the same
 * message — the integration bus is at-least-once — registers exactly one sale.
 */
#[CommandHandler]
final class RegisterSaleHandler
{
    public function __construct(
        private readonly SaleRepository $sales,
    ) {}

    public function __invoke(RegisterSale $command): void
    {
        $this->sales->save(new Sale(
            saleId: SaleId::generate(),
            vendingMachineId: $command->vendingMachineId,
            selectorCode: $command->selectorCode,
            priceInCents: $command->priceInCents,
            soldAt: $command->soldAt,
            sourceEventId: $command->sourceEventId,
        ));
    }
}
