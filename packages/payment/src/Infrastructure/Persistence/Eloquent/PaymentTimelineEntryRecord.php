<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $payment_id
 * @property string $project_id
 * @property string $type
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property string $summary
 * @property array<string, mixed> $details
 * @property string|null $correlation_id
 * @property string|null $trace_id
 */
final class PaymentTimelineEntryRecord extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'payment_timeline_entries';

    protected $keyType = 'string';

    protected $fillable = ['id', 'payment_id', 'project_id', 'type', 'occurred_at', 'summary', 'details', 'correlation_id', 'trace_id'];

    protected $casts = [
        'details' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * ADR-0059 / decision D5 — journey details (customer phone, names) are
     * kept `payment.timeline_retention_days` (90 by default).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $configured = config('payment.timeline_retention_days', 90);
        $days = is_numeric($configured) ? (int) $configured : 90;

        return self::query()->where('created_at', '<', now()->subDays($days));
    }
}
