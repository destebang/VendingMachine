<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\HttpApplication;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;

final class SelectItemHttpTest extends TestCase
{
    private HttpApplication $application;

    protected function setUp(): void
    {
        $container = ContainerFactory::create();
        /** @var RouteCollection $routes */
        $routes = require dirname(__DIR__, levels: 2) . '/config/routes.php';

        $this->application = new HttpApplication($container, $routes);
    }

    /**
     * Worked example: 1, 0.25, 0.25, GET-SODA -> SODA.
     */
    public function test_example_1_a_soda_is_vended_with_no_change(): void
    {
        $id = $this->servicedMachine();

        $this->insertCoin($id, 100);
        $this->insertCoin($id, 25);
        $this->insertCoin($id, 25);

        $response = $this->select($id, 'SODA');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            [
                'id' => $id,
                'selectorCode' => 'SODA',
                'priceInCents' => 150,
                'change' => [],
                'insertedFundsInCents' => 0,
            ],
            $this->payload($response),
        );

        $state = $this->state($id);

        self::assertSame(9, $state['stock'][2]['stock']);
        self::assertSame(0, $state['insertedFundsInCents']);
    }

    /**
     * Worked example: 1, GET-WATER -> WATER, 0.25, 0.10.
     */
    public function test_example_2_a_water_is_vended_with_change_from_the_reserve(): void
    {
        $id = $this->servicedMachine();

        $this->insertCoin($id, 100);

        $response = $this->select($id, 'WATER');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            [
                'id' => $id,
                'selectorCode' => 'WATER',
                'priceInCents' => 65,
                'change' => [25, 10],
                'insertedFundsInCents' => 0,
            ],
            $this->payload($response),
        );

        $state = $this->state($id);

        self::assertSame(9, $state['stock'][0]['stock']);
        self::assertSame(
            [
                ['denominationInCents' => 25, 'quantity' => 39],
                ['denominationInCents' => 10, 'quantity' => 39],
                ['denominationInCents' => 5, 'quantity' => 40],
            ],
            $state['cashReserve'],
        );
        self::assertSame(0, $state['insertedFundsInCents']);
    }

    /**
     * Worked example: a rejection with a clear response — 0.25, GET-WATER keeps
     * the inserted funds so the customer can top up.
     */
    public function test_example_3_an_insufficient_funds_rejection_is_reported_and_keeps_the_balance(): void
    {
        $id = $this->servicedMachine();

        $this->insertCoin($id, 25);

        $response = $this->select($id, 'WATER');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('InsufficientFunds', $this->payload($response)['error']);
        self::assertSame(25, $this->state($id)['insertedFundsInCents']);
    }

    public function test_selecting_an_unstocked_product_is_reported_as_out_of_stock(): void
    {
        $id = $this->servicedMachine();

        $this->insertCoin($id, 100);

        $response = $this->select($id, 'CHIPS');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('OutOfStock', $this->payload($response)['error']);
        self::assertSame(100, $this->state($id)['insertedFundsInCents']);
    }

    public function test_an_insufficient_change_rejection_is_reported_and_refunds_the_balance(): void
    {
        $id = $this->provisionMachine();
        $this->service($id, [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]], []);

        $this->insertCoin($id, 100);

        $response = $this->select($id, 'WATER');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('InsufficientChangeAvailable', $this->payload($response)['error']);
        self::assertSame(0, $this->state($id)['insertedFundsInCents']);
    }

    public function test_selecting_on_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->select(VendingMachineId::generate()->toString(), 'WATER');

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function provisionMachine(): string
    {
        $response = $this->application->handle(Request::create('/vending-machines', 'POST'));

        return $this->payload($response)['id'];
    }

    /**
     * A machine stocked with WATER (0.65), JUICE (1.00) and SODA (1.50) and a
     * full change reserve, as in the specification's worked examples.
     */
    private function servicedMachine(): string
    {
        $id = $this->provisionMachine();

        $this->service(
            $id,
            [
                ['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10],
                ['selectorCode' => 'JUICE', 'priceInCents' => 100, 'stock' => 10],
                ['selectorCode' => 'SODA', 'priceInCents' => 150, 'stock' => 10],
            ],
            [
                ['denominationInCents' => 25, 'quantity' => 40],
                ['denominationInCents' => 10, 'quantity' => 40],
                ['denominationInCents' => 5, 'quantity' => 40],
            ],
        );

        return $id;
    }

    /**
     * @param list<array{selectorCode: string, priceInCents: int, stock: int}> $stock
     * @param list<array{denominationInCents: int, quantity: int}>            $cashReserve
     */
    private function service(string $id, array $stock, array $cashReserve): void
    {
        $response = $this->application->handle(Request::create(
            '/vending-machines/' . $id . '/service',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['stock' => $stock, 'cashReserve' => $cashReserve], JSON_THROW_ON_ERROR),
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function insertCoin(string $id, int $denominationInCents): void
    {
        $response = $this->application->handle(Request::create(
            '/vending-machines/' . $id . '/coins',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['denominationInCents' => $denominationInCents], JSON_THROW_ON_ERROR),
        ));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    private function select(string $id, string $selectorCode): Response
    {
        return $this->application->handle(Request::create(
            '/vending-machines/' . $id . '/selections',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['selectorCode' => $selectorCode], JSON_THROW_ON_ERROR),
        ));
    }

    /**
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
    private function state(string $id): array
    {
        return $this->payload($this->application->handle(Request::create('/vending-machines/' . $id, 'GET')));
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
}
