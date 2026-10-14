<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\ValueObject\Uuid;
use PHPUnit\Framework\TestCase;

final class EventIdTest extends TestCase
{
    public function test_it_is_a_uuid_that_mints_its_own_type(): void
    {
        $id = EventId::generate();

        self::assertInstanceOf(Uuid::class, $id);
        // The inherited factory must hand back the concrete id, not the base.
        self::assertInstanceOf(EventId::class, EventId::fromString($id->toString()));
    }
}
