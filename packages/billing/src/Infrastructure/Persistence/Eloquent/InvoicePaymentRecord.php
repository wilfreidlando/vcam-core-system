<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0073 §2 — the collection attempt, as it lies on the disk.
 *
 * `payment_id` is an **opaque reference** to the `payment` module: no
 * foreign key crosses a module boundary (ADR-0033 §5).
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $project_id
 * @property string $payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property int|null $confirmed_amount_minor
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property string|null $ledger_transaction_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class InvoicePaymentRecord extends Model
{
    use HasUuids;

    protected $table = 'invoice_payments';

    protected $fillable = [
        'id',
        'invoice_id',
        'project_id',
        'payment_id',
        'amount_minor',
        'currency',
        'status',
        'confirmed_amount_minor',
        'failure_reason',
        'settled_at',
        'ledger_transaction_id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount_minor' => 'int',
        'confirmed_amount_minor' => 'int',
        'settled_at' => 'datetime',
    ];
}
