<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use stdClass;

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
            invoicePrefix: $record->invoice_prefix,
            defaultTaxRateBps: $record->default_tax_rate_bps,
            manualReceiptApprovalThresholdMinor: $record->manual_receipt_approval_threshold_minor,
            retainInvoiceIdentity: $record->retain_invoice_identity,
            documentBranding: $this->brandingOf($record->document_branding),
            meterMappings: $this->mappingsOf($record->meter_mappings),
            refundFailedUsage: $record->refund_failed_usage,
            requireCustomerRef: $record->require_customer_ref,
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
            'invoice_prefix' => $settings->invoicePrefix,
            'default_tax_rate_bps' => $settings->defaultTaxRateBps,
            'manual_receipt_approval_threshold_minor' => $settings->manualReceiptApprovalThresholdMinor,
            'retain_invoice_identity' => $settings->retainInvoiceIdentity,
            'document_branding' => $this->branding($settings->documentBranding),
            'meter_mappings' => $this->branding($settings->meterMappings),
            'refund_failed_usage' => $settings->refundFailedUsage,
            'require_customer_ref' => $settings->requireCustomerRef,
        ];
    }

    /**
     * The branding of the documents is a **map** — and the column refuses
     * anything else (`billing_settings_document_branding_check`). An empty
     * PHP array would be stored as a JSON array: it is written as an empty
     * object instead (the same idiom as the metadata of a customer).
     *
     * @param array<string, mixed> $branding
     */
    private function branding(array $branding): string
    {
        return (string) json_encode($branding === [] ? new stdClass : $branding);
    }

    /**
     * The branding as a map — the column holds a JSON **object**.
     *
     * @return array<string, mixed>
     */
    private function brandingOf(string $json): array
    {
        $decoded = json_decode($json, true);
        /** @var array<string, mixed> $branding */
        $branding = is_array($decoded) ? $decoded : [];

        return $branding;
    }

    /**
     * @return array<string, string>
     */
    private function mappingsOf(?string $json): array
    {
        $decoded = $json === null ? [] : json_decode($json, true);
        $mappings = [];

        foreach (is_array($decoded) ? $decoded : [] as $capability => $meter) {
            if (is_string($meter)) {
                $mappings[(string) $capability] = $meter;
            }
        }

        return $mappings;
    }
}
