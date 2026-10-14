<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Event;

use App\Shared\Domain\Event\EventId;
use App\Shared\Domain\Event\InvalidEventIdException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventIdTest extends TestCase
{
    public function test_generate_mints_a_valid_unique_event_id(): void
    {
        $first = EventId::generate();
        $second = EventId::generate();

        // Reading a minted id back must round-trip exactly, and no two ids
        // minted in the same run may ever collide.
        self::assertSame($first->toString(), EventId::fromString($first->toString())->toString());
        self::assertNotSame($first->toString(), $second->toString());
    }

    public function test_from_string_accepts_the_id_case_insensitively_and_stores_it_lower_case(): void
    {
        $id = EventId::generate();

        $readBack = EventId::fromString(strtoupper($id->toString()));

        self::assertSame($id->toString(), $readBack->toString());
        self::assertTrue($readBack->equals($id));
    }

    /**
     * @param string $invalidId
     */
    #[DataProvider('notAUuidV7')]
    public function test_from_string_rejects_something_that_is_not_a_uuidv7(string $invalidId): void
    {
        $this->expectException(InvalidEventIdException::class);

        EventId::fromString($invalidId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAUuidV7(): iterable
    {
        yield 'plain words' => ['not-an-event-id'];
        yield 'empty string' => [''];
        yield 'uuidv4 version nibble' => ['b6c4d8e0-1a2b-4c3d-8e4f-0123456789ab'];
        yield 'wrong variant nibble' => ['0190f0c2-1a2b-7c3d-2e4f-0123456789ab'];
        yield 'missing segment' => ['0190f0c2-1a2b-7c3d-8e4f-0123456789a'];
    }
}
