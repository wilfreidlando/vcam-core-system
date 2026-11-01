<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0071 §4 — the settings a project owns.
 *
 * One row per project: the **project id is the primary key**, so there
 * is no surrogate id to generate — `uniqueIds()` is emptied on purpose
 * (the trait would otherwise fill a column named `id` that the table
 * does not have).
 *
 * @property string $project_id
 * @property string|null $meter_mappings
 * @property bool $refund_failed_usage
 * @property bool $require_customer_ref
 * @property string $timezone
 * @property string $default_currency
 * @property int $renewal_lead_days
 * @property int $incomplete_expiry_hours
 * @property string $invoice_prefix
 * @property int $default_tax_rate_bps
 * @property int $manual_receipt_approval_threshold_minor
 * @property bool $retain_invoice_identity
 * @property string $document_branding the JSON **object** of the document branding
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class BillingSettingsRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'project_id';

    protected $table = 'billing_settings';

    protected $fillable = [
        'project_id',
        'timezone',
        'default_currency',
        'renewal_lead_days',
        'incomplete_expiry_hours',
        'invoice_prefix',
        'default_tax_rate_bps',
        'manual_receipt_approval_threshold_minor',
        'retain_invoice_identity',
        'document_branding',
        'meter_mappings',
        'refund_failed_usage',
        'require_customer_ref',
    ];

    protected $casts = [
        'renewal_lead_days' => 'int',
        'incomplete_expiry_hours' => 'int',
        'default_tax_rate_bps' => 'int',
        'manual_receipt_approval_threshold_minor' => 'int',
        'retain_invoice_identity' => 'bool',
        'refund_failed_usage' => 'bool',
        'require_customer_ref' => 'bool',
        // `document_branding` is deliberately NOT cast to `array`: the
        // column is a jsonb **object**
        // (`billing_settings_document_branding_check`), and the cast
        // would encode an empty map as `[]` — which the column refuses.
        // The repository serialises it as an object instead.
    ];

    /**
     * The primary key is the project id: there is no id to generate.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
