<?php

declare(strict_types=1);

namespace App\Shared\Application\Bus\Attribute;

use Attribute;

/**
 * Marks a service as a subscriber of domain events.
 *
 * Framework-free marker: the composition root (`App\Kernel\ContainerFactory`)
 * reads it during autoconfiguration and tags every public method that accepts
 * a domain event for Symfony Messenger, so reacting to a recorded fact is just
 * adding a method to the subscriber class. Unlike a command handler, a
 * subscriber is one of potentially many reactions to the same event.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DomainEventSubscriber {}
