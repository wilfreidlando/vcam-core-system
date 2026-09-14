<?php

declare(strict_types=1);

namespace Core\Audit\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $actor
 * @property string $action
 * @property string $resource
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed>|null $context
 * @property string|null $correlation_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class AuditLogRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = ['actor', 'action', 'resource', 'before', 'after', 'context', 'correlation_id', 'created_at'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
