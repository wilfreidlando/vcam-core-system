<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;

/**
 * ADR-0071 §4 — the billing settings of a project.
 *
 * One row per project, the project id being the primary key. A project
 * that has never changed anything has no row: the **defaults** are
 * returned instead (ADR-0071 §4), never an error — every project has
 * settings.
 */
final class EloquentBillingSettingsRepository implements BillingSettingsRepository
{
    public function find(string $projectId): BillingSettings
    {
        $record = BillingSettingsRecord::query()
            ->where('project_id', $projectId)
            ->first();

        if ($record === null) {
            return BillingSettings::defaultsFor($projectId);
        }

        return new BillingSettings(
            projectId: $record->project_id,
            timezone: $record->timezone,
            defaultCurrency: $record->default_currency,
            renewalLeadDays: $record->renewal_lead_days,
            incompleteExpiryHours: $record->incomplete_expiry_hours,
        );
    }

    public function save(BillingSettings $settings): void
    {
        $attributes = $this->toAttributes($settings);

        // `toBase()` — `exists()` is forwarded by Eloquent's builder to
        // the query builder rather than declared on it, which trips a
        // Larastan false positive (the same workaround as
        // EloquentPaymentRepository).
        if (BillingSettingsRecord::query()->where('project_id', $settings->projectId)->toBase()->exists()) {
            BillingSettingsRecord::query()
                ->where('project_id', $settings->projectId)
                ->update($attributes);

            return;
        }

        BillingSettingsRecord::query()->create(
            ['project_id' => $settings->projectId] + $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(BillingSettings $settings): array
    {
        return [
            'timezone' => $settings->timezone,
            'default_currency' => $settings->defaultCurrency,
            'renewal_lead_days' => $settings->renewalLeadDays,
            'incomplete_expiry_hours' => $settings->incompleteExpiryHours,
        ];
    }
}
