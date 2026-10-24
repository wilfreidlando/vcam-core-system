<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $url
 * @property string $secret_encrypted
 * @property array<int, string> $events
 * @property bool $active
 * @property string|null $previous_secret_encrypted
 * @property \Carbon\CarbonImmutable|null $previous_secret_expires_at
 * @property \Carbon\CarbonImmutable|null $secret_rotated_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class WebhookEndpointRecord extends Model
{
    use HasUuids;

    protected $table = 'webhook_endpoints';

    protected $fillable = ['id', 'project_id', 'url', 'secret_encrypted', 'events', 'active', 'previous_secret_encrypted', 'previous_secret_expires_at', 'secret_rotated_at'];

    protected $casts = [
        'events' => 'array',
        'active' => 'bool',
        'previous_secret_expires_at' => 'immutable_datetime',
        'secret_rotated_at' => 'immutable_datetime',
    ];
}
