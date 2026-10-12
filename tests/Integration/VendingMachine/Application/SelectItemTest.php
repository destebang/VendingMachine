<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\ContainerFactory;
use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\Tests\Integration\IntegrationTestCase;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineResult;
use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\SelectItem\SelectItemResult;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Exercises a selection against the real command bus, MongoDB write model and
 * Redis read model, with no in-memory doubles.
 */
final class SelectItemTest extends IntegrationTestCase
{
    private ContainerBuilder $container;

    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    protected function setUp(): void
    {
        $this->container = ContainerFactory::create();
        $this->repository = new MongoVendingMachineRepository(
            DocumentManagerFactory::create(),
            new DomainEventCollector(),
        );
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
    }

    public function test_a_selection_vends_the_item_pays_change_and_updates_both_stores(): void
    {
        $id = $this->servicedMachine();

        $this->dispatch($this->container, InsertCoin::fromInput($id, 100));

        /** @var SelectItemResult $result */
        $result = $this->dispatch($this->container, SelectItem::fromInput($id, 'WATER'));

        self::assertSame('WATER', $result->vendedItem->selectorCode);
        self::assertSame(65, $result->vendedItem->price->amountInCents());
        self::assertSame([25, 10], $result->vendedItem->changeDue->denominationsInCents());

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(9, $stored->state()->stock->quantityOf('WATER'));
        self::assertSame(0, $stored->state()->insertedFunds->amount()->amountInCents());
        self::assertSame([25 => 39, 10 => 39, 5 => 40], $stored->state()->cashReserve->counts());

        self::assertSame(
            [
                'id' => $id,
                'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 9]],
                'cashReserve' => [
                    ['denominationInCents' => 25, 'quantity' => 39],
                    ['denominationInCents' => 10, 'quantity' => 39],
                    ['denominationInCents' => 5, 'quantity' => 40],
                ],
                'insertedFundsInCents' => 0,
            ],
            $this->readModel->get($id)?->toArray(),
        );
    }

    public function test_an_insufficient_change_rejection_refunds_the_balance_in_both_stores(): void
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                [],
            ),
        );

        $this->dispatch($this->container, InsertCoin::fromInput($id, 100));

        $thrown = $this->dispatchExpectingFailure($this->container, SelectItem::fromInput($id, 'WATER'));

        self::assertInstanceOf(InsufficientChangeAvailableException::class, $thrown);

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(0, $stored->state()->insertedFunds->amount()->amountInCents());
        self::assertSame(10, $stored->state()->stock->quantityOf('WATER'));

        $projected = $this->readModel->get($id);

        self::assertNotNull($projected);
        self::assertSame(0, $projected->insertedFundsInCents);
    }

    public function test_selecting_from_an_unknown_machine_is_rejected(): void
    {
        $thrown = $this->dispatchExpectingFailure(
            $this->container,
            SelectItem::fromInput(VendingMachineId::generate()->toString(), 'WATER'),
        );

        self::assertInstanceOf(VendingMachineNotFoundException::class, $thrown);
    }

    /**
     * Provisions and services a machine selling WATER (0.65) with a full change
     * reserve, returning its id.
     */
    private function servicedMachine(): string
    {
        $id = $this->provisionId();

        $this->dispatch(
            $this->container,
            ServiceVendingMachine::fromInput(
                $id,
                [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                [
                    ['denominationInCents' => 25, 'quantity' => 40],
                    ['denominationInCents' => 10, 'quantity' => 40],
                    ['denominationInCents' => 5, 'quantity' => 40],
                ],
            ),
        );

        return $id;
    }

    private function provisionId(): string
    {
        /** @var ProvisionVendingMachineResult $result */
        $result = $this->dispatch($this->container, new ProvisionVendingMachine());

        return $result->id();
    }
}
