<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
 * @property \Carbon\CarbonImmutable|null $delivered_at
 * @property string|null $replayed_from_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class WebhookDeliveryRecord extends Model
{
    use HasUuids;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'id', 'endpoint_id', 'project_id', 'event_id', 'event_type', 'payload',
        'status', 'attempts', 'next_retry_at', 'last_attempt_at', 'last_error',
        'delivered_at', 'replayed_from_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'int',
        'next_retry_at' => 'immutable_datetime',
        'last_attempt_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];
}
