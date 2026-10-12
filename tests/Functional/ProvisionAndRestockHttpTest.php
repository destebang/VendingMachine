<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\DocumentManagerFactory;
use App\Kernel\HttpApplication;
use App\VendingMachine\Domain\Model\Money;
use App\VendingMachine\Domain\Model\VendingMachine;
use App\VendingMachine\Domain\Model\VendingMachineId;
use App\VendingMachine\Infrastructure\Persistence\Mongo\MongoVendingMachineRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProvisionAndRestockHttpTest extends TestCase
{
    private HttpApplication $application;

    protected function setUp(): void
    {
        $container = ContainerFactory::create();
        $routes = require dirname(__DIR__, 2) . '/config/routes.php';

        $this->application = new HttpApplication($container, $routes);
    }

    public function test_a_machine_can_be_provisioned_serviced_and_read_back_over_http(): void
    {
        $provisionResponse = $this->application->handle(Request::create('/vending-machines', 'POST'));

        self::assertSame(Response::HTTP_CREATED, $provisionResponse->getStatusCode());

        $id = json_decode((string) $provisionResponse->getContent(), true, flags: JSON_THROW_ON_ERROR)['id'];

        $initial = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame(Response::HTTP_OK, $initial->getStatusCode());
        self::assertSame(
            ['id' => $id, 'stock' => [], 'change' => [], 'balanceInCents' => 0],
            json_decode((string) $initial->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );

        $serviceResponse = $this->application->handle($this->serviceRequest($id, [
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'change' => [['denominationInCents' => 100, 'quantity' => 5]],
        ]));

        self::assertSame(Response::HTTP_OK, $serviceResponse->getStatusCode());

        $afterService = $this->application->handle(Request::create('/vending-machines/' . $id, 'GET'));

        self::assertSame([
            'id' => $id,
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'change' => [['denominationInCents' => 100, 'quantity' => 5]],
            'balanceInCents' => 0,
        ], json_decode((string) $afterService->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_reading_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->application->handle(
            Request::create('/vending-machines/' . VendingMachineId::generate()->toString(), 'GET'),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_servicing_an_unknown_machine_returns_not_found(): void
    {
        $response = $this->application->handle(
            $this->serviceRequest(VendingMachineId::generate()->toString(), ['stock' => [], 'change' => []]),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_servicing_while_a_transaction_is_pending_is_rejected(): void
    {
        $repository = new MongoVendingMachineRepository(DocumentManagerFactory::create());
        $id = VendingMachineId::generate();

        $repository->save(VendingMachine::reconstitute($id, Money::fromCents(50), 0, [], []));

        $response = $this->application->handle($this->serviceRequest($id->toString(), [
            'stock' => [['selectorCode' => 'WATER', 'priceInCents' => 65, 'stock' => 10]],
            'change' => [],
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('PendingTransactionInProgress', $payload['error']);

        $stored = $repository->find($id);

        self::assertNotNull($stored);
        self::assertSame([], $stored->stock());
        self::assertSame([], $stored->change());
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
