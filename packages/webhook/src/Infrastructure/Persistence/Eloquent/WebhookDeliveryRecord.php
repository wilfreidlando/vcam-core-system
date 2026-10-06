<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $endpoint_id
 * @property string $project_id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property \Carbon\CarbonImmutable|null $next_retry_at
 * @property \Carbon\CarbonImmutable|null $last_attempt_at
 * @property string|null $last_error
 * @property int|null $last_response_status
 * @property string|null $last_response_body
 * @property \Carbon\CarbonImmutable|null $delivered_at
 * @property string|null $replayed_from_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class WebhookDeliveryRecord extends Model
{
    use HasUuids;
    use MassPrunable;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'id', 'endpoint_id', 'project_id', 'event_id', 'event_type', 'payload',
        'status', 'attempts', 'next_retry_at', 'last_attempt_at', 'last_error',
        'last_response_status', 'last_response_body',
        'delivered_at', 'replayed_from_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'int',
        'next_retry_at' => 'immutable_datetime',
        'last_attempt_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    /**
     * Audit 2026-10-03 §4.6 — terminal deliveries (payload + receiver
     * response) are kept `webhook.retention_days`, then deleted by the
     * scheduled `model:prune`. `pending` rows are never pruned.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $configured = config('webhook.retention_days', 90);
        $days = is_numeric($configured) ? (int) $configured : 90;

        // @phpstan-ignore staticMethod.dynamicCall (Eloquent builder forwarding — see EloquentWalletBalanceRepository)
        return self::query()
            ->whereIn('status', ['delivered', 'dead', 'canceled'])
            ->where('updated_at', '<', now()->subDays($days));
    }
}
