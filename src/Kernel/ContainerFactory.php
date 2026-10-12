<?php

declare(strict_types=1);

namespace App\Kernel;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Hand-assembled dependency injection container, replacing the service
 * wiring that `symfony/framework-bundle` would otherwise provide. Shared by
 * both the HTTP front controller (public/index.php) and the CLI
 * (bin/console) so both entry points see the exact same services.
 */
final class ContainerFactory
{
    public static function create(): ContainerBuilder
    {
        $projectDir = dirname(__DIR__, levels: 2);

        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator($projectDir . '/config'));
        $loader->load('services.yaml');

        $container->compile();

        return $container;
    }
}
