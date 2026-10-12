<?php

declare(strict_types=1);

use App\VendingMachine\Infrastructure\Http\HealthCheckController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();

$routes->add('health_check', new Route(
    path: '/health',
    defaults: ['_controller' => HealthCheckController::class],
    methods: ['GET'],
));

return $routes;
