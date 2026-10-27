<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0073 §1 — the invoice, as it lies on the disk.
 *
 * The personal values of the customer are **never** stored in clear:
 * `customer_snapshot` holds the ciphertexts of ADR-0083 §2, versioned by
 * the cipher, and the project's own reference beside them.
 *
 * @property string $id
 * @property string $project_id
 * @property string $customer_id
 * @property string|null $subscription_id
 * @property string|null $period_id
 * @property string|null $number
 * @property string $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $amount_paid_minor
 * @property string $customer_snapshot the JSON **object** of the frozen customer
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property \Illuminate\Support\Carbon|null $issued_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string|null $ledger_transaction_id
 * @property int $version
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class InvoiceRecord extends Model
{
    use HasUuids;

    protected $table = 'invoices';

    protected $fillable = [
        'id',
        'project_id',
        'customer_id',
        'subscription_id',
        'period_id',
        'number',
        'status',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'total_minor',
        'amount_paid_minor',
        'customer_snapshot',
        'due_at',
        'issued_at',
        'paid_at',
        'voided_at',
        'void_reason',
        'ledger_transaction_id',
        'version',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'subtotal_minor' => 'int',
        'tax_minor' => 'int',
        'total_minor' => 'int',
        'amount_paid_minor' => 'int',
        'version' => 'int',
        'due_at' => 'datetime',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
        // `customer_snapshot` is deliberately NOT cast to `array`: the
        // column is a jsonb **object**, and the cast would encode an
        // empty map as `[]` — which the column refuses. The repository
        // serialises it as an object instead.
    ];
}
