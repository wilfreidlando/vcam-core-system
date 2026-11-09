<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0117 §2 — a supplement of a contract.
 *
 * The row carries the frozen terms of the purchase — the meter, the
 * quantity, the unit price and the currency — and the life of the
 * purchase in `status`.
 *
 * @property string $id
 * @property string $project_id
 * @property string $subscription_id
 * @property string $customer_id
 * @property string $meter
 * @property string $feature_key
 * @property int $quantity
 * @property int $unit_price_minor
 * @property string $currency
 * @property string $status
 * @property string|null $invoice_id
 * @property string|null $period_id
 * @property string|null $idempotency_key
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $canceled_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class SubscriptionSupplementRecord extends Model
{
    use HasUuids;

    /**
     * The table has a `created_at` and an `updated_at`, but Eloquent must
     * not stamp them: the instants belong to the domain's `Clock`, never
     * to the database (ADR-0072 §1).
     */
    public $timestamps = false;

    protected $table = 'subscription_supplements';

    protected $fillable = [
        'id',
        'project_id',
        'subscription_id',
        'customer_id',
        'meter',
        'feature_key',
        'quantity',
        'unit_price_minor',
        'currency',
        'status',
        'invoice_id',
        'period_id',
        'idempotency_key',
        'activated_at',
        'canceled_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'quantity' => 'int',
        'unit_price_minor' => 'int',
        'activated_at' => 'datetime',
        'canceled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
