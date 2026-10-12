<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\HttpApplication;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InsertCoinHttpTest extends TestCase
{
    private HttpApplication $application;

    protected function setUp(): void
    {
        $container = ContainerFactory::create();
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

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
            ['id' => $id, 'stock' => [], 'cashReserve' => [], 'insertedFundsInCents' => 150],
            json_decode((string) $state->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_inserting_a_denomination_the_machine_does_not_accept_is_rejected(): void
    {
        $id = $this->provisionMachine();

        $response = $this->application->handle($this->coinRequest($id, 50));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('InvalidDenomination', $payload['error']);

        $state = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame(
            0,
            json_decode((string) $state->getContent(), true, flags: JSON_THROW_ON_ERROR)['insertedFundsInCents'],
        );
    }

    public function test_inserting_into_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->application->handle(
            $this->coinRequest(VendingMachineId::generate()->toString(), 25),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function provisionMachine(): string
    {
        $response = $this->application->handle(Request::create('/vending-machines', 'POST'));

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['id'];
    }

    private function insertCoin(string $id, int $denominationInCents): int
    {
        $response = $this->application->handle($this->coinRequest($id, $denominationInCents));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['insertedFundsInCents'];
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
