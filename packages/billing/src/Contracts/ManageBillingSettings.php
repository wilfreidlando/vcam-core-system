<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The console's door to the commerce settings of a project. The scope is
 * required (ADR-0069 §2); a project outside it is not found. An invalid
 * value raises `Core\Billing\Domain\Exception\InvalidBillingSettings`.
 */
interface ManageBillingSettings
{
    public function get(string $projectId, AccessScope $scope): ?BillingSettingsView;

    public function save(SaveBillingSettingsCommand $command, AccessScope $scope): ?BillingSettingsView;
}
