<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string|null $correlation_id
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class EventOutboxRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'event_outbox';

    protected $fillable = ['id', 'project_id', 'type', 'payload', 'correlation_id', 'published_at', 'created_at'];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
