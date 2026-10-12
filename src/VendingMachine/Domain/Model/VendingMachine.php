<?php

declare(strict_types=1);

namespace App\VendingMachine\Domain\Model;

/**
 * Write-side aggregate root for a single vending machine.
 *
 * This is a bootstrap shell: it only carries identity and the optimistic
 * concurrency version expected by the repository/persistence layer. Business
 * behaviour (accepting coins, dispensing products, returning change,
 * servicing) is added use case by use case on top of this skeleton.
 */
final class VendingMachine
{
    private function __construct(
        private readonly VendingMachineId $id,
        private int $version,
    ) {
    }

    public static function create(VendingMachineId $id): self
    {
        return new self($id, 0);
    }

    /**
     * Reconstructs an aggregate from persisted state, e.g. when loaded by a
     * repository. Not for application code to call directly.
     */
    public static function reconstitute(VendingMachineId $id, int $version): self
    {
        return new self($id, $version);
    }

    public function id(): VendingMachineId
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * Bumps the optimistic-concurrency version. Called by the repository
     * after every successful write, never by business logic.
     */
    public function incrementVersion(): void
    {
        $this->version++;
    }
}
