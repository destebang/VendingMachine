<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Kernel\DependencyInjection\SingleHandlerPerMessagePass;
use App\Shared\Application\Bus\Attribute\CommandHandler;
use App\Shared\Application\Bus\Attribute\QueryHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Messenger\DependencyInjection\MessengerPass;

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

        self::registerHandlerAttributes($container);

        $container->addCompilerPass(new MessengerPass());
        $container->addCompilerPass(new SingleHandlerPerMessagePass(), priority: -10);

        $container->compile();

        return $container;
    }

    /**
     * Turns the framework-free handler markers into the Messenger tag, so a
     * use case is wired by adding its handler class alone.
     */
    private static function registerHandlerAttributes(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(CommandHandler::class, static function (ChildDefinition $definition): void {
            $definition->addTag('messenger.message_handler', ['bus' => 'command.bus']);
        });

        $container->registerAttributeForAutoconfiguration(QueryHandler::class, static function (ChildDefinition $definition): void {
            $definition->addTag('messenger.message_handler', ['bus' => 'query.bus']);
        });
    }
}
