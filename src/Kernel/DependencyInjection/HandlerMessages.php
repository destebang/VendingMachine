<?php

declare(strict_types=1);

namespace App\Kernel\DependencyInjection;

use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reads the message(s) a handler service declares on its `__invoke()`
 * signature, mirroring how Symfony Messenger guesses them.
 */
final class HandlerMessages
{
    /**
     * @return list<string>
     */
    public static function of(ContainerBuilder $container, string $serviceId): array
    {
        $class = $container->getDefinition($serviceId)->getClass();
        $reflection = $class === null ? null : $container->getReflectionClass($class, false);

        if ($reflection === null || !$reflection->hasMethod('__invoke')) {
            throw new RuntimeException(sprintf(
                'Invalid handler service "%s": it must expose an "__invoke()" method typed with the message it handles.',
                $serviceId,
            ));
        }

        $parameters = $reflection->getMethod('__invoke')->getParameters();

        if ($parameters === []) {
            throw new RuntimeException(sprintf(
                'Invalid handler service "%s": "__invoke()" must declare the message it handles.',
                $serviceId,
            ));
        }

        $messages = self::classNamesIn($parameters[0]->getType());

        if ($messages === []) {
            throw new RuntimeException(sprintf(
                'Invalid handler service "%s": "__invoke()" must type-hint a message class.',
                $serviceId,
            ));
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private static function classNamesIn(?ReflectionType $type): array
    {
        $messages = [];

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $candidate) {
            if (!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin()) {
                continue;
            }

            $messages[] = $candidate->getName();
        }

        return $messages;
    }
}
