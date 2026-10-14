<?php

declare(strict_types=1);

use App\Sales\Infrastructure\Http\ListSalesController;
use App\VendingMachine\Infrastructure\Http\GetVendingMachineController;
use App\VendingMachine\Infrastructure\Http\HealthCheckController;
use App\VendingMachine\Infrastructure\Http\InsertCoinController;
use App\VendingMachine\Infrastructure\Http\ProvisionVendingMachineController;
use App\VendingMachine\Infrastructure\Http\ReturnCoinController;
use App\VendingMachine\Infrastructure\Http\SelectItemController;
use App\VendingMachine\Infrastructure\Http\ServiceVendingMachineController;
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

$routes->add('service_vending_machine', new Route(
    path: '/vending-machines/{id}/service',
    defaults: ['_controller' => ServiceVendingMachineController::class],
    methods: ['POST'],
));

$routes->add('insert_coin', new Route(
    path: '/vending-machines/{id}/coins',
    defaults: ['_controller' => InsertCoinController::class],
    methods: ['POST'],
));

$routes->add('select_item', new Route(
    path: '/vending-machines/{id}/selections',
    defaults: ['_controller' => SelectItemController::class],
    methods: ['POST'],
));

$routes->add('return_coin', new Route(
    path: '/vending-machines/{id}/return-coin',
    defaults: ['_controller' => ReturnCoinController::class],
    methods: ['POST'],
));

$routes->add('get_vending_machine', new Route(
    path: '/vending-machines/{id}',
    defaults: ['_controller' => GetVendingMachineController::class],
    methods: ['GET'],
));

$routes->add('list_sales', new Route(
    path: '/sales',
    defaults: ['_controller' => ListSalesController::class],
    methods: ['GET'],
));

return $routes;
