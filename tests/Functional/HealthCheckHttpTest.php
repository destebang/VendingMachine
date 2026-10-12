<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Kernel\ContainerFactory;
use App\Kernel\HttpApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;

final class HealthCheckHttpTest extends TestCase
{
    public function test_the_health_endpoint_reports_mongo_and_redis_as_reachable(): void
    {
        $container = ContainerFactory::create();
        /** @var RouteCollection $routes */
        $routes = require dirname(__DIR__, levels: 2) . '/config/routes.php';

        $application = new HttpApplication($container, $routes);

        $response = $application->handle(Request::create('/health', 'GET'));

        self::assertSame(200, $response->getStatusCode());

        /** @var array{mongo: bool, redis: bool, healthy: bool} $payload */
        $payload = json_decode((string) $response->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['mongo']);
        self::assertTrue($payload['redis']);
        self::assertTrue($payload['healthy']);
    }
}
