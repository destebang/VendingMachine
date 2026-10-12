<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\VendingMachine\Domain\Exception\InvalidVendingMachineIdException;

/**
 * Identifies a single physical (or virtual) vending machine. Backed by a
 * UUIDv7 so identifiers are globally unique, unguessable, and sortable by
 * creation time without needing a central sequence.
 *
 * Self-contained: the UUIDv7 is generated and validated here, so the Domain
 * keeps no dependency on a framework UUID library.
 */
final readonly class VendingMachineId
{
    /**
     * Canonical UUIDv7: 8-4-4-4-12 hex digits, version nibble 7 and RFC 4122
     * variant bits. Matched case-insensitively; the value is stored lower-case.
     */
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private function __construct(
        private string $value,
    ) {}

    public static function generate(): self
    {
        $bytes = random_bytes(16);

        // Bytes 0-5: 48-bit big-endian Unix timestamp in milliseconds.
        $milliseconds = (int) floor(microtime(true) * 1000);
        $bytes[0] = chr(($milliseconds >> 40) & 0xff);
        $bytes[1] = chr(($milliseconds >> 32) & 0xff);
        $bytes[2] = chr(($milliseconds >> 24) & 0xff);
        $bytes[3] = chr(($milliseconds >> 16) & 0xff);
        $bytes[4] = chr(($milliseconds >> 8) & 0xff);
        $bytes[5] = chr($milliseconds & 0xff);

        // Byte 6: version 7 in the high nibble, random bits in the low nibble.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);

        // Byte 8: RFC 4122 variant (10xx) in the top bits, random below.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return new self(self::canonical($bytes));
    }

    public static function fromString(string $id): self
    {
        if (preg_match(self::PATTERN, $id) !== 1) {
            throw InvalidVendingMachineIdException::notAUuidV7($id);
        }

        return new self(strtolower($id));
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

    private static function canonical(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, offset: 0, length: 8),
            substr($hex, offset: 8, length: 4),
            substr($hex, offset: 12, length: 4),
            substr($hex, offset: 16, length: 4),
            substr($hex, offset: 20, length: 12),
        );
    }
}
