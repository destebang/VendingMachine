<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\DocumentManagerFactory;
use App\Kernel\HttpApplication;
use App\Shared\Infrastructure\EventBus\DomainEventCollector;
use App\VendingMachine\Domain\Model\Denomination;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;

final class ProvisionAndServiceHttpTest extends TestCase
{
    private HttpApplication $application;

    protected function setUp(): void
    {
        $container = ContainerFactory::create();
        /** @var RouteCollection $routes */
        $routes = require dirname(__DIR__, levels: 2) . '/config/routes.php';

        $this->application = new HttpApplication($container, $routes);
    }

    public function test_a_machine_can_be_provisioned_serviced_and_read_back_over_http(): void
    {
        $provisionResponse = $this->application->handle(Request::create('/vending-machines', 'POST'));

        self::assertSame(Response::HTTP_CREATED, $provisionResponse->getStatusCode());

        $id = $this->payload($provisionResponse)['id'];

        $initial = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame(Response::HTTP_OK, $initial->getStatusCode());
        self::assertSame(
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 0],
            $this->payload($initial),
        );

        $serviceResponse = $this->application->handle($this->serviceRequest($id, [
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'cashReserve' => [['denominationInCents' => 100, 'quantity' => 5]],
        ]));

        self::assertSame(Response::HTTP_OK, $serviceResponse->getStatusCode());

        $afterService = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame(
            [
                'id' => $id,
                'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
                'cashReserve' => [['denominationInCents' => 100, 'quantity' => 5]],
                'insertedFundsInCents' => 0,
            ],
            $this->payload($afterService),
        );
    }

    public function test_reading_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->application->handle(Request::create(
            '/vending-machines/' . VendingMachineId::generate()->toString(),
            'GET',
        ));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_servicing_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->application->handle($this->serviceRequest(VendingMachineId::generate()->toString(), [
            'stock' => [],
            'cashReserve' => [],
        ]));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_servicing_while_a_transaction_is_pending_is_rejected(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create(), new DomainEventCollector());
        $id = VendingMachineId::generate();

        $machine = VendingMachine::create($id);
        $machine->insertCoin(Denomination::of(Money::fromCents(25)));
        $repository->save($machine);

        $response = $this->application->handle($this->serviceRequest($id->toString(), [
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'cashReserve' => [],
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());

        $payload = $this->payload($response);

        self::assertSame('PendingTransactionInProgress', $payload['error']);

        $stored = $repository->find($id);

        self::assertNotNull($stored);
        self::assertTrue($stored->state()->stock->isEmpty());
        self::assertTrue($stored->state()->cashReserve->isEmpty());
    }

    /**
     * The union of every key the JSON API can return, so a test can read any of
     * them without re-checking. A test never reads a key the response omits.
     *
     * @return array{
     *     id: string,
     *     selectorCode: string,
     *     priceInCents: int,
     *     change: list<int>,
     *     returnedCoins: list<int>,
     *     insertedFundsInCents: int,
     *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
     *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
     *     error: string
     * }
     */
    private function payload(Response $response): array
    {
        /** @var array{
         *     id: string,
         *     selectorCode: string,
         *     priceInCents: int,
         *     change: list<int>,
         *     returnedCoins: list<int>,
         *     insertedFundsInCents: int,
         *     stock: list<array{selectorCode: string, priceInCents: int, stock: int}>,
         *     cashReserve: list<array{denominationInCents: int, quantity: int}>,
         *     error: string
         * } $payload */
        $payload = json_decode((string) $response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function serviceRequest(string $id, array $payload): Request
    {
        return Request::create(
            '/vending-machines/' . $id . '/service',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
