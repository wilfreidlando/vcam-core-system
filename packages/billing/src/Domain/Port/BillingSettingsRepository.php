<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\BillingSettings;

/**
 * ADR-0071 §4 — the persistence port of the billing settings of a
 * project.
 *
 * One row per project; a project that has never changed anything has no
 * row and gets the defaults (ADR-0071 §4).
 */
interface BillingSettingsRepository
{
    /**
     * The settings of a project — the **defaults** when the project has
     * no row yet, never an error: every project has settings.
     */
    public function find(string $projectId): BillingSettings;

    /**
     * Persists the settings of a project, created or updated.
     */
    public function save(BillingSettings $settings): void;
}
