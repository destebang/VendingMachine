<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use Stringable;

/**
 * A value object that can be rendered as a plain string.
 *
 * The shared identity base (`Uuid`) implements it, so a caller can take any of
 * the ids a context exposes as a string without knowing the concrete id type.
 * Extending `Stringable` keeps `(string) $id` and `$id->toString()` in step.
 */
interface StringInterface extends Stringable
{
    public function toString(): string;
}
