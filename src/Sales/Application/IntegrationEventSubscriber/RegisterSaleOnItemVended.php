<?php

declare(strict_types=1);

namespace App\Sales\Application\IntegrationEventSubscriber;

use App\Sales\Application\IntegrationEvent\ItemVendedV1Received;
use App\Sales\Application\RegisterSale\RegisterSale;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\IntegrationEvent\IntegrationEventSubscriber;

/**
 * The Sales context's reaction to a vended item: it maps the received message
 * to `RegisterSale` and dispatches it on the command bus.
 *
 * The subscriber is the only place where the consumer-side message and the
 * use case meet, so the command stays free of the transport's shape. Marking
 * the class `#[IntegrationEventSubscriber]` autoconfigures it onto the
 * integration bus; the worker consuming `sales.item_vended` is what runs it.
 */
#[IntegrationEventSubscriber]
final class RegisterSaleOnItemVended
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(ItemVendedV1Received $message): void
    {
        $this->commandBus->handle(new RegisterSale(
            vendingMachineId: $message->aggregateId,
            selectorCode: $message->selectorCode,
            priceInCents: $message->priceInCents,
            soldAt: $message->occurredAt,
            sourceEventId: $message->id,
        ));
    }
}
