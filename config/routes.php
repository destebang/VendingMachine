<?php

declare(strict_types=1);

use App\VendingMachine\Infrastructure\Http\GetVendingMachineController;
use App\VendingMachine\Infrastructure\Http\HealthCheckController;
use App\VendingMachine\Infrastructure\Http\ProvisionVendingMachineController;
use App\VendingMachine\Infrastructure\Http\RestockVendingMachineController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();

$routes->add('health_check', new Route(
    path: '/health',
    defaults: ['_controller' => HealthCheckController::class],
    methods: ['GET'],
));

$routes->add('provision_vending_machine', new Route(
    path: '/vending-machines',
    defaults: ['_controller' => ProvisionVendingMachineController::class],
    methods: ['POST'],
));

$routes->add('restock_vending_machine', new Route(
    path: '/vending-machines/{id}/service',
    defaults: ['_controller' => RestockVendingMachineController::class],
    methods: ['POST'],
));

$routes->add('get_vending_machine', new Route(
    path: '/vending-machines/{id}',
    defaults: ['_controller' => GetVendingMachineController::class],
    methods: ['GET'],
));

return $routes;
