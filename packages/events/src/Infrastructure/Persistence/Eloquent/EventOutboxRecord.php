<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string|null $correlation_id
 * @property string|null $trace_id
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property bool $skip_webhook_endpoint_fan_out
 * @property string $environment
 */
final class EventOutboxRecord extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    // Without this, Eloquent treats `id` as an auto-incrementing integer
    // key and silently casts the UUID primary key to (int) 0 on read — a
    // pre-existing gap that never surfaced because nothing read a row
    // back before the relay (ADR-0040). Every other UUID-keyed model in
    // the Core sets this (see WalletBalanceRecord, or HasUuids on
    // PaymentRecord/WalletRecord).
    public $incrementing = false;

    protected $table = 'event_outbox';

    protected $keyType = 'string';

    protected $fillable = ['id', 'project_id', 'type', 'payload', 'correlation_id', 'trace_id', 'published_at', 'created_at', 'skip_webhook_endpoint_fan_out', 'environment'];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'skip_webhook_endpoint_fan_out' => 'bool',
    ];

    /**
     * Audit 2026-10-03 §4.6 — rows already relayed are kept
     * `events.retention_days`, then deleted by the scheduled `model:prune`.
     * An unpublished row is never pruned.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $configured = config('events.retention_days', 30);
        $days = is_numeric($configured) ? (int) $configured : 30;

        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder forwarding — see EloquentWalletBalanceRepository)
        return self::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<', now()->subDays($days));
    }
}
