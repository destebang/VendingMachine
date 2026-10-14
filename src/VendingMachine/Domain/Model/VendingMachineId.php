<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

use App\Shared\Domain\ValueObject\Uuid;

/**
 * Identifies a single physical (or virtual) vending machine. A distinct type so
 * a machine id can never be handed to a repository expecting another identity;
 * the UUIDv7 is generated and validated once, in the shared `Uuid` base.
 */
final readonly class VendingMachineId extends Uuid {}
