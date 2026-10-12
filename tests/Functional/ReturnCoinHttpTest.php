<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\HttpApplication;
use App\VendingMachine\Domain\Model\VendingMachineId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReturnCoinHttpTest extends TestCase
{
    private HttpApplication $application;

    protected function setUp(): void
    {
        $container = ContainerFactory::create();
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        $this->application = new HttpApplication($container, $routes);
    }

    /**
     * Worked example: 0.10, 0.10, RETURN-COIN -> 0.10, 0.10.
     */
    public function test_the_readme_example_returns_two_dimes(): void
    {
        $id = $this->provisionMachine();

        $this->insertCoin($id, 10);
        $this->insertCoin($id, 10);

        $response = $this->returnCoin($id);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([
            'id' => $id,
            'returnedCoins' => [10, 10],
            'insertedFundsInCents' => 0,
        ], $this->payload($response));

        self::assertSame(0, $this->state($id)['insertedFundsInCents']);
    }

    public function test_returning_with_nothing_inserted_is_a_no_op(): void
    {
        $id = $this->provisionMachine();

        $response = $this->returnCoin($id);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([
            'id' => $id,
            'returnedCoins' => [],
            'insertedFundsInCents' => 0,
        ], $this->payload($response));
    }

    public function test_returning_on_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->returnCoin(VendingMachineId::generate()->toString());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('VendingMachineNotFound', $this->payload($response)['error']);
    }

    private function provisionMachine(): string
    {
        $response = $this->application->handle(Request::create('/vending-machines', 'POST'));

        return $this->payload($response)['id'];
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

    private function returnCoin(string $id): Response
    {
        return $this->application->handle(Request::create('/vending-machines/' . $id . '/return-coin', 'POST'));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $id): array
    {
        return $this->payload($this->application->handle(Request::create('/vending-machines/' . $id, 'GET')));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
