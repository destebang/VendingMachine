<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\ValueObject\Uuid;

/**
 * Identifies a single recorded domain event. A distinct type so an event id can
 * never be handed to a repository expecting another identity; the UUIDv7 is
 * generated and validated once, in the shared `Uuid` base.
 */
final readonly class EventId extends Uuid {}
