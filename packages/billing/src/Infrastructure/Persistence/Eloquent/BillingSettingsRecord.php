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
 * @property string $timezone
 * @property string $default_currency
 * @property int $renewal_lead_days
 * @property int $incomplete_expiry_hours
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
    ];

    protected $casts = [
        'renewal_lead_days' => 'int',
        'incomplete_expiry_hours' => 'int',
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
