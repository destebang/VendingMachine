<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ReturnCoin;

use App\VendingMachine\Domain\Model\VendingMachineState;
use App\VendingMachine\Domain\Read\MachineStateView;

/**
 * Result of returning inserted funds: the coins handed back — exactly those the
 * customer inserted — and the machine's state afterwards, whose
 * `insertedFundsInCents` is the reset balance (always zero).
 */
final class ReturnCoinResult
{
    /**
     * @param list<int> $returnedCoinsInCents
     */
    public function __construct(
        public readonly array $returnedCoinsInCents,
        public readonly VendingMachineState $state,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     returnedCoins: list<int>,
     *     insertedFundsInCents: int
     * }
     */
    public function toArray(): array
    {
        $state = MachineStateView::fromState($this->state);

        return [
            'id' => $state->id,
            'returnedCoins' => $this->returnedCoinsInCents,
            'insertedFundsInCents' => $state->insertedFundsInCents,
        ];
    }
}
