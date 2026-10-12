<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Identifies a single physical (or virtual) vending machine. Backed by a
 * UUIDv7 so identifiers are globally unique, unguessable, and sortable by
 * creation time without needing a central sequence.
 */
final class VendingMachineId
{
    private function __construct(
        private readonly UuidV7 $uuid,
    ) {
    }

    public static function generate(): self
    {
        return new self(Uuid::v7());
    }

    public static function fromString(string $id): self
    {
        return new self(UuidV7::fromString($id));
    }

    public function toString(): string
    {
        return $this->uuid->toRfc4122();
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
