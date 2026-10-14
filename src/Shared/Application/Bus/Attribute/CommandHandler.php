<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus\Attribute;

use Attribute;

/**
 * Marks a service as the handler of a command.
 *
 * Framework-free marker: the composition root (`App\Kernel\ContainerFactory`)
 * reads it during autoconfiguration and tags the service for Symfony
 * Messenger, so adding a use case is just adding the handler class. The
 * attribute itself knows nothing about the framework that consumes it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CommandHandler {}
