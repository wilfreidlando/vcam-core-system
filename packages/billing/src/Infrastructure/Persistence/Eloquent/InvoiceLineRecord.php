<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-0073 §1 — a line of an invoice, as it lies on the disk.
 *
 * The tax of the line is **stored**, not recomputed: it was rounded
 * half-up on that line at the issuance, and the document is frozen
 * (ADR-0094 §1).
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $project_id
 * @property string $kind
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $amount_minor
 * @property int $tax_rate_bps
 * @property int $tax_amount_minor
 * @property \Illuminate\Support\Carbon|null $period_start
 * @property \Illuminate\Support\Carbon|null $period_end
 * @property \Illuminate\Support\Carbon $created_at
 */
final class InvoiceLineRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'invoice_lines';

    protected $fillable = [
        'id',
        'invoice_id',
        'project_id',
        'kind',
        'description',
        'quantity',
        'unit_amount_minor',
        'amount_minor',
        'tax_rate_bps',
        'tax_amount_minor',
        'period_start',
        'period_end',
        'created_at',
    ];

    protected $casts = [
        'quantity' => 'int',
        'unit_amount_minor' => 'int',
        'amount_minor' => 'int',
        'tax_rate_bps' => 'int',
        'tax_amount_minor' => 'int',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'created_at' => 'datetime',
    ];
}
