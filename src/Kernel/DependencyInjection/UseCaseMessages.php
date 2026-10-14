<?php

declare(strict_types=1);

namespace App\Kernel\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Finds the use-case classes the container knows about, following the
 * `Application/<UseCase>/<UseCase>.php` layout (the class name matches the
 * directory it lives in).
 */
final class UseCaseMessages
{
    /**
     * @param list<string> $namespaces
     *
     * @return list<string>
     */
    public static function in(ContainerBuilder $container, array $namespaces): array
    {
        $messages = [];

        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();

            if ($definition->isAbstract() || $class === null || !self::followsAnyLayout($class, $namespaces)) {
                continue;
            }

            $messages[$class] = true;
        }

        return array_keys($messages);
    }

    /**
     * @param list<string> $namespaces
     */
    private static function followsAnyLayout(string $class, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (self::followsLayout($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    private static function followsLayout(string $class, string $namespace): bool
    {
        if (!str_starts_with($class, $namespace)) {
            return false;
        }

        $segments = explode('\\', $class);
        $shortName = array_pop($segments);

        return $shortName === array_pop($segments);
    }
}
