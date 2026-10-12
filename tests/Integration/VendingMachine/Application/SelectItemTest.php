<?php

declare(strict_types=1);

namespace App\Tests\Integration\VendingMachine\Application;

use App\Kernel\DocumentManagerFactory;
use App\Kernel\RedisClientFactory;
use App\VendingMachine\Application\InsertCoin\InsertCoin;
use App\VendingMachine\Application\InsertCoin\InsertCoinHandler;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachine;
use App\VendingMachine\Application\ProvisionVendingMachine\ProvisionVendingMachineHandler;
use App\VendingMachine\Application\SelectItem\SelectItem;
use App\VendingMachine\Application\SelectItem\SelectItemHandler;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachine;
use App\VendingMachine\Application\ServiceVendingMachine\ServiceVendingMachineHandler;
use App\VendingMachine\Domain\Exception\InsufficientChangeAvailableException;
use App\VendingMachine\Domain\Exception\VendingMachineNotFoundException;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use App\VendingMachine\Infrastructure\ReadModel\Redis\RedisVendingMachineStateReadModelRepository;
use PHPUnit\Framework\TestCase;

/**
 * Exercises a selection against the real MongoDB write model and the real Redis
 * read model, with no in-memory doubles.
 */
final class SelectItemTest extends TestCase
{
    private MongoVendingMachineRepository $repository;

    private RedisVendingMachineStateReadModelRepository $readModel;

    private ProvisionVendingMachineHandler $provision;

    private ServiceVendingMachineHandler $service;

    private InsertCoinHandler $insertCoin;

    private SelectItemHandler $selectItem;

    protected function setUp(): void
    {
        $this->repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $this->readModel = new RedisVendingMachineStateReadModelRepository(RedisClientFactory::create());
        $this->provision = new ProvisionVendingMachineHandler($this->repository, $this->readModel);
        $this->service = new ServiceVendingMachineHandler($this->repository, $this->readModel);
        $this->insertCoin = new InsertCoinHandler($this->repository, $this->readModel);
        $this->selectItem = new SelectItemHandler($this->repository, $this->readModel);
    }

    public function test_a_selection_vends_the_item_pays_change_and_updates_both_stores(): void
    {
        $id = $this->servicedMachine();

        ($this->insertCoin)(InsertCoin::fromInput($id, 100));

        $result = ($this->selectItem)(SelectItem::fromInput($id, 'WATER'));

        self::assertSame('WATER', $result->vendedItem->selectorCode);
        self::assertSame(65, $result->vendedItem->price->amountInCents());
        self::assertSame([25, 10], $result->vendedItem->changeDue->denominationsInCents());
        self::assertSame(0, $result->state->insertedFundsInCents);

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
            $this->readModel->get($id),
        );
    }

    public function test_an_insufficient_change_rejection_refunds_the_balance_in_both_stores(): void
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->service)(ServiceVendingMachine::fromInput(
            $id,
            [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            [],
        ));

        ($this->insertCoin)(InsertCoin::fromInput($id, 100));

        try {
            ($this->selectItem)(SelectItem::fromInput($id, 'WATER'));
            self::fail('Expected the selection to be rejected for insufficient change.');
        } catch (InsufficientChangeAvailableException $exception) {
            self::assertStringContainsString('change', $exception->getMessage());
        }

        $stored = $this->repository->find(VendingMachineId::fromString($id));

        self::assertNotNull($stored);
        self::assertSame(0, $stored->state()->insertedFunds->amount()->amountInCents());
        self::assertSame(10, $stored->state()->stock->quantityOf('WATER'));

        $projected = $this->readModel->get($id);

        self::assertNotNull($projected);
        self::assertSame(0, $projected['insertedFundsInCents']);
    }

    public function test_selecting_from_an_unknown_machine_is_rejected(): void
    {
        $this->expectException(VendingMachineNotFoundException::class);

        ($this->selectItem)(SelectItem::fromInput(VendingMachineId::generate()->toString(), 'WATER'));
    }

    /**
     * Provisions and services a machine selling WATER (0.65) with a full change
     * reserve, returning its id.
     */
    private function servicedMachine(): string
    {
        $id = ($this->provision)(new ProvisionVendingMachine())->id();

        ($this->service)(ServiceVendingMachine::fromInput(
            $id,
            [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            [
                ['denominationInCents' => 25, 'quantity' => 40],
                ['denominationInCents' => 10, 'quantity' => 40],
                ['denominationInCents' => 5, 'quantity' => 40],
            ],
        ));

        return $id;
    }
}
