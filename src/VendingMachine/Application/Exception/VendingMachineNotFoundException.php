<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\Exception;

use App\VendingMachine\Domain\Model\VendingMachineId;
use RuntimeException;

/**
 * Thrown by an application use case when the write model has no machine for
 * the requested id. Driving adapters translate it into a 404 / non-zero exit.
 */
final class VendingMachineNotFoundException extends RuntimeException
{
    public static function withId(VendingMachineId $id): self
    {
        return new self(sprintf('Vending machine "%s" was not found.', $id->toString()));
    }
}
