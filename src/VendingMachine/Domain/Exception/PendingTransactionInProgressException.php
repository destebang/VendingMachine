<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Exception;

use App\VendingMachine\Domain\Model\VendingMachineId;
use RuntimeException;

/**
 * Thrown when a service visit (restock) is attempted while a customer
 * transaction is still in flight — i.e. the machine holds inserted money that
 * has not yet been settled. Servicing the machine then would rewrite its
 * state out from under the customer.
 */
final class PendingTransactionInProgressException extends RuntimeException
{
    public static function cannotRestock(VendingMachineId $id): self
    {
        return new self(sprintf(
            'Cannot service vending machine "%s": a customer transaction is in progress.',
            $id->toString(),
        ));
    }
}
