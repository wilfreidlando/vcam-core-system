<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0072 §1 — the contract between a customer and a project.
 *
 * The price, the currency and the interval are **frozen** on the
 * contract: they come from the plan version the contract was signed on
 * (ADR-0071 §Conséquences). The price is in minor units (G5).
 *
 * @property string $id
 * @property string $project_id
 * @property string $customer_id
 * @property string $plan_id
 * @property string $plan_version_id
 * @property string $status
 * @property int $price_minor
 * @property string $currency
 * @property string $interval_unit
 * @property int $interval_count
 * @property \Illuminate\Support\Carbon $anchor_at
 * @property string|null $current_period_id
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property bool $cancel_at_period_end
 * @property string|null $pending_plan_version_id
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property \Illuminate\Support\Carbon|null $canceled_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property int $version
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class SubscriptionRecord extends Model
{
    use HasUuids;

    protected $table = 'subscriptions';

    protected $fillable = [
        'id',
        'project_id',
        'customer_id',
        'plan_id',
        'plan_version_id',
        'status',
        'price_minor',
        'currency',
        'interval_unit',
        'interval_count',
        'anchor_at',
        'current_period_id',
        'trial_ends_at',
        'cancel_at_period_end',
        'pending_plan_version_id',
        'paused_at',
        'canceled_at',
        'ended_at',
        'version',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'price_minor' => 'int',
        'interval_count' => 'int',
        'cancel_at_period_end' => 'bool',
        'version' => 'int',
        // The instants of the contract — read as dates, never as strings.
        'anchor_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'paused_at' => 'datetime',
        'canceled_at' => 'datetime',
        'ended_at' => 'datetime',
    ];
}
