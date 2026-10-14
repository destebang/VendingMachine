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

final class InsertCoinHttpTest extends TestCase
{
    /** @var list<int> */
    private const ACCEPTED_DENOMINATIONS_IN_CENTS = [5, 10, 25, 100];

    private HttpApplication $application;

    protected function setUp(): void
    {
        $container = ContainerFactory::create();
        /** @var RouteCollection $routes */
        $routes = require dirname(__DIR__, levels: 2) . '/config/routes.php';

        $this->application = new HttpApplication($container, $routes);
    }

    public function test_inserting_coins_over_http_accumulates_and_is_read_back(): void
    {
        $id = $this->provisionMachine();

        self::assertSame(25, $this->insertCoin($id, 25));
        self::assertSame(50, $this->insertCoin($id, 25));
        self::assertSame(150, $this->insertCoin($id, 100));

        $state = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame(Response::HTTP_OK, $state->getStatusCode());
        self::assertSame(
            [
                'id' => $id,
                'acceptedDenominationsInCents' => self::ACCEPTED_DENOMINATIONS_IN_CENTS,
                'stock' => [],
                'cashReserve' => [],
                'insertedFundsInCents' => 150,
            ],
            json_decode((string) $state->getContent(), associative: true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_machine_rejects_a_coin_outside_its_configured_set(): void
    {
        $id = $this->provisionMachine([10, 50]);

        $response = $this->application->handle($this->coinRequest($id, 25));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('InvalidDenomination', $this->payload($response)['error']);
    }

    public function test_inserting_a_denomination_the_machine_does_not_accept_is_rejected(): void
    {
        $id = $this->provisionMachine();

        $response = $this->application->handle($this->coinRequest($id, 50));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $payload = $this->payload($response);

        self::assertSame('InvalidDenomination', $payload['error']);

        $state = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame(0, $this->payload($state)['insertedFundsInCents']);
    }

    public function test_inserting_into_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->application->handle($this->coinRequest(VendingMachineId::generate()->toString(), 25));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * @param list<int> $acceptedDenominationsInCents
     */
    private function provisionMachine(array $acceptedDenominationsInCents = self::ACCEPTED_DENOMINATIONS_IN_CENTS): string
    {
        $response = $this->application->handle(Request::create(
            '/vending-machines',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['acceptedDenominationsInCents' => $acceptedDenominationsInCents], JSON_THROW_ON_ERROR),
        ));

        return $this->payload($response)['id'];
    }

    private function insertCoin(string $id, int $denominationInCents): int
    {
        $response = $this->application->handle($this->coinRequest($id, $denominationInCents));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return $this->payload($response)['insertedFundsInCents'];
    }

    /**
     * The union of every key the JSON API can return, so a test can read any of
     * them without re-checking. A test never reads a key the response omits.
     *
     * @return array{
     *     id: string,
     *     acceptedDenominationsInCents: list<int>,
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
         *     acceptedDenominationsInCents: list<int>,
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

    private function coinRequest(string $id, int $denominationInCents): Request
    {
        return Request::create(
            '/vending-machines/' . $id . '/coins',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['denominationInCents' => $denominationInCents], JSON_THROW_ON_ERROR),
        );
    }
}
