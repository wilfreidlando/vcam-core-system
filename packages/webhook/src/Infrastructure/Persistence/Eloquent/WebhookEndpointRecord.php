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
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class WebhookEndpointRecord extends Model
{
    use HasUuids;

    protected $table = 'webhook_endpoints';

    protected $fillable = ['id', 'project_id', 'url', 'secret_encrypted', 'events', 'active'];

    protected $casts = [
        'events' => 'array',
        'active' => 'bool',
    ];
}
