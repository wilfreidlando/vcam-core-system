<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0072 §1 — a period of a contract.
 *
 * The table carries no `updated_at`: a period is immutable once it is
 * closed, and the instants of its transitions are the ones the domain
 * holds.
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $project_id
 * @property int $seq
 * @property string $kind
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property string $state
 * @property string|null $invoice_id
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class SubscriptionPeriodRecord extends Model
{
    use HasUuids;

    /**
     * The table has a `created_at` and no `updated_at`: Eloquent must not
     * try to stamp a column that does not exist.
     */
    public $timestamps = false;

    protected $table = 'subscription_periods';

    protected $fillable = [
        'id',
        'subscription_id',
        'project_id',
        'seq',
        'kind',
        'starts_at',
        'ends_at',
        'state',
        'invoice_id',
        'reason',
        'paid_at',
        'created_at',
    ];

    protected $casts = [
        'seq' => 'int',
        // The table carries no `updated_at` (`$timestamps = false`), so
        // Eloquent does not cast the timestamps on its own — without
        // these, the repository would read a string where it expects a
        // date.
        'created_at' => 'datetime',
        'paid_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
