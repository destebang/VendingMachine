<?php

declare(strict_types=1);

namespace App\Shared\Application\IntegrationEvent;

use Attribute;

/**
 * Marks a service as a subscriber of integration events.
 *
 * Framework-free marker, the consuming counterpart of the integration bus:
 * the composition root reads it during autoconfiguration and tags the
 * subscriber's public event methods for the Messenger worker consuming the
 * integration transport. Unlike a command handler, a subscriber is one of
 * potentially many reactions to the same message.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class IntegrationEventSubscriber {}
