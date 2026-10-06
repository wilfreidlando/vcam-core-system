<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $delivery_id
 * @property int $attempt
 * @property \Carbon\CarbonImmutable $attempted_at
 * @property string $url
 * @property array<string, string> $request_headers
 * @property string $request_body
 * @property int|null $response_status
 * @property string|null $response_body
 * @property int|null $duration_ms
 * @property string|null $error
 * @property string $outcome
 * @property string|null $correlation_id
 * @property string|null $trace_id
 */
final class WebhookDeliveryAttemptRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'webhook_delivery_attempts';

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'delivery_id', 'attempt', 'attempted_at', 'url', 'request_headers', 'request_body',
        'response_status', 'response_body', 'duration_ms', 'error', 'outcome', 'correlation_id', 'trace_id',
    ];

    protected $casts = [
        'attempt' => 'int',
        'attempted_at' => 'immutable_datetime',
        'request_headers' => 'array',
        'response_status' => 'int',
        'duration_ms' => 'int',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.uP';
}
