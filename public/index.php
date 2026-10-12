<?php

declare(strict_types=1);

use App\Kernel\ContainerFactory;
use App\Kernel\HttpApplication;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$container = ContainerFactory::create();

/** @var \Symfony\Component\Routing\RouteCollection $routes */
$routes = require dirname(__DIR__) . '/config/routes.php';

$application = new HttpApplication($container, $routes);

$response = $application->handle(Request::createFromGlobals());
$response->send();
