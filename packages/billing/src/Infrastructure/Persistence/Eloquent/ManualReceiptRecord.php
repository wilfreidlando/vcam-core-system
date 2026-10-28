<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0073 §4 — the offline receipt, as it lies on the disk.
 *
 * `recorded_by` and `approved_by` are **console accounts** (uuids), and
 * the database refuses the self-approval: `manual_receipts_four_eyes`
 * (ADR-0073 §4).
 *
 * @property string $id
 * @property string $project_id
 * @property string $invoice_id
 * @property string $method
 * @property string $reference
 * @property int $amount_minor
 * @property string $currency
 * @property \Illuminate\Support\Carbon $received_at
 * @property string $recorded_by
 * @property string|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property string $status
 * @property string|null $evidence_ref
 * @property string|null $note
 * @property string|null $ledger_transaction_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class ManualReceiptRecord extends Model
{
    use HasUuids;

    protected $table = 'manual_receipts';

    protected $fillable = [
        'id',
        'project_id',
        'invoice_id',
        'method',
        'reference',
        'amount_minor',
        'currency',
        'received_at',
        'recorded_by',
        'approved_by',
        'approved_at',
        'status',
        'evidence_ref',
        'note',
        'ledger_transaction_id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount_minor' => 'int',
        'received_at' => 'datetime',
        'approved_at' => 'datetime',
    ];
}
