<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\DocumentManagerFactory;
use App\Kernel\HttpApplication;
use App\Sales\Infrastructure\Persistence\Mongo\SaleDocument;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;

/**
 * `GET /sales` — the demo read of what the Sales context has registered. Seeds
 * a sale directly in the write model, then asserts the endpoint serves it
 * through the query bus.
 */
final class ListSalesHttpTest extends TestCase
{
    private const string SALE_ID = '0190f0c2-1a2b-7c3d-8e4f-0123456789ab';

    private const string VENDING_MACHINE_ID = '0190f0c2-1a2b-7c3d-8e4f-000000000001';

    private const string SOURCE_EVENT_ID = '01a12f94-62ac-771b-a72a-f8a35ea379bb';

    private HttpApplication $application;

    protected function setUp(): void
    {
        $documentManager = DocumentManagerFactory::create();
        $sales = $documentManager->getDocumentCollection(SaleDocument::class);
        $sales->deleteMany([]);
        $sales->insertOne([
            '_id' => self::SALE_ID,
            'vendingMachineId' => self::VENDING_MACHINE_ID,
            'selectorCode' => 'WATER',
            'priceInCents' => 65,
            'soldAt' => '2026-10-14T10:30:00+00:00',
            'sourceEventId' => self::SOURCE_EVENT_ID,
        ]);

        $container = ContainerFactory::create();
        /** @var RouteCollection $routes */
        $routes = require dirname(__DIR__, levels: 2) . '/config/routes.php';

        $this->application = new HttpApplication($container, $routes);
    }

    public function test_it_lists_the_registered_sales(): void
    {
        $response = $this->application->handle(Request::create('/sales', 'GET'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            [
                [
                    'saleId' => self::SALE_ID,
                    'vendingMachineId' => self::VENDING_MACHINE_ID,
                    'selectorCode' => 'WATER',
                    'priceInCents' => 65,
                    'soldAt' => '2026-10-14T10:30:00+00:00',
                    'sourceEventId' => self::SOURCE_EVENT_ID,
                ],
            ],
            json_decode((string) $response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR),
        );
    }
}
