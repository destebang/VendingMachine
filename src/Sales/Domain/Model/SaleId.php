<?php

declare(strict_types=1);

namespace App\Sales\Domain\Model;

use App\Shared\Domain\ValueObject\Uuid;

/**
 * Identifies a single registered sale. A distinct type so a sale id can never
 * be handed to a repository expecting another identity; the UUIDv7 is
 * generated and validated once, in the shared `Uuid` base.
 */
final readonly class SaleId extends Uuid {}
