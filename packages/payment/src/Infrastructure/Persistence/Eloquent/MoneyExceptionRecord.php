<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $kind
 * @property string $severity
 * @property int $amount_minor
 * @property string $currency
 * @property int $booked_amount_minor
 * @property string|null $payment_id
 * @property string|null $subject_ref
 * @property string $summary
 * @property array<string, mixed> $details
 * @property string $status
 * @property string|null $resolution
 * @property string|null $resolution_reference
 * @property string|null $resolution_note
 * @property string|null $resolved_by
 * @property string|null $approved_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
final class MoneyExceptionRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'money_exceptions';

    protected $fillable = [
        'id', 'project_id', 'kind', 'severity', 'amount_minor', 'currency', 'booked_amount_minor',
        'payment_id', 'subject_ref', 'summary', 'details', 'status', 'resolution', 'resolution_reference',
        'resolution_note', 'resolved_by', 'approved_by', 'created_at', 'updated_at', 'resolved_at',
    ];

    protected $casts = [
        'amount_minor' => 'int',
        'booked_amount_minor' => 'int',
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
