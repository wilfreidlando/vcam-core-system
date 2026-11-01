<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0071 §1 — the terms of a plan.
 *
 * The price is in **minor units** of a `char(3)` currency, never a float
 * (G5); `features` is a jsonb of *named* features (ADR-0071 §3), never a
 * price.
 *
 * The table carries no `updated_at`: a published version is frozen
 * (ADR-0071 §2), and the trigger of the next migration refuses the write
 * whatever the path.
 *
 * @property string $id
 * @property string $plan_id
 * @property string $project_id
 * @property int $version
 * @property int $price_minor
 * @property string $currency
 * @property string $interval_unit
 * @property int $interval_count
 * @property int $trial_days
 * @property int $grace_days
 * @property bool $grace_access
 * @property string $features the JSON **object** of the plan's features
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PlanVersionRecord extends Model
{
    use HasUuids;

    /**
     * The table has a `created_at` and no `updated_at`: Eloquent must not
     * try to stamp a column that does not exist.
     */
    public $timestamps = false;

    protected $table = 'plan_versions';

    protected $fillable = [
        'id',
        'plan_id',
        'project_id',
        'version',
        'price_minor',
        'currency',
        'interval_unit',
        'interval_count',
        'trial_days',
        'grace_days',
        'grace_access',
        'features',
        'status',
        'published_at',
    ];

    protected $casts = [
        'version' => 'int',
        'price_minor' => 'int',
        'interval_count' => 'int',
        'trial_days' => 'int',
        'grace_days' => 'int',
        'grace_access' => 'bool',
        // The table carries no `updated_at` (`$timestamps = false`), so
        // Eloquent does not cast the timestamps on its own — without
        // these, the repository would read a string where it expects a
        // date.
        'created_at' => 'datetime',
        'published_at' => 'datetime',
        // `features` is deliberately NOT cast to `array`: the column is a
        // jsonb **object** (`plan_versions_features_object_check`), and
        // the cast would encode an empty map as `[]` — which the column
        // refuses. The repository serialises it as an object instead.
    ];
}
