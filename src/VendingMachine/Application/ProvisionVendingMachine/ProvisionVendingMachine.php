<?php

declare(strict_types=1);

namespace App\VendingMachine\Application\ProvisionVendingMachine;

/**
 * Provisions a brand-new vending machine: a fresh identity with empty stock,
 * empty cash reserve and no inserted funds. Carries no payload — everything a new
 * machine starts with is defined by the aggregate.
 */
final class ProvisionVendingMachine {}
