<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\Exception\InvalidUuidException;
use Symfony\Component\Uid\UuidV7;

/**
 * Base identity shared by the contexts: a canonical UUIDv7 rendered as a
 * string.
 *
 * A UUIDv7 is globally unique and sortable by the moment it was minted, without
 * needing a central sequence — which is what lets an event store, outbox or bus
 * deduplicate and reference one exact occurrence of a fact. Concrete ids
 * (`EventId`, `VendingMachineId`, `SaleId`) extend this base to get a distinct
 * type, so one context's id can never be handed to another's repository, while
 * sharing the generation and validation once.
 *
 * The value is generated and validated by `symfony/uid`, so the shared kernel
 * keeps a single, well-tested implementation of UUIDv7 instead of a copy per
 * id. Only the id's own value is exposed; the Symfony type stays inside.
 */
readonly class Uuid
{
    final protected function __construct(
        private string $value,
    ) {}

    public static function generate(): static
    {
        return new static(UuidV7::generate());
    }

    public static function fromString(string $id): static
    {
        if (!UuidV7::isValid($id)) {
            throw InvalidUuidException::notAUuidV7($id);
        }

        return new static(strtolower($id));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
