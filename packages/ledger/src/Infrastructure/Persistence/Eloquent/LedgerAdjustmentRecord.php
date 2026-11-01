<?php

declare(strict_types=1);

namespace Core\Ledger\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $source_kind
 * @property string $source_reference
 * @property string $destination_kind
 * @property string $destination_reference
 * @property int $amount_minor
 * @property string $currency
 * @property string $reason
 * @property string $status
 * @property string $proposed_by
 * @property string|null $approved_by
 * @property string|null $ledger_transaction_id
 * @property string|null $reverses_transaction_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 */
final class LedgerAdjustmentRecord extends Model
{
    /**
     * The workflow table carries no `updated_at`: a correction's history
     * is its `created_at` and its `approved_at`.
     */
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'ledger_adjustments';

    protected $fillable = [
        'id', 'project_id', 'source_kind', 'source_reference', 'destination_kind', 'destination_reference',
        'amount_minor', 'currency', 'reason', 'status', 'proposed_by', 'approved_by',
        'ledger_transaction_id', 'reverses_transaction_id', 'created_at', 'approved_at',
    ];

    protected $casts = [
        'amount_minor' => 'int',
        'created_at' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
