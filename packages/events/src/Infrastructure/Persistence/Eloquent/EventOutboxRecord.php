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
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property bool $skip_webhook_endpoint_fan_out
 */
final class EventOutboxRecord extends Model
{
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

    protected $fillable = ['id', 'project_id', 'type', 'payload', 'correlation_id', 'published_at', 'created_at', 'skip_webhook_endpoint_fan_out'];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'skip_webhook_endpoint_fan_out' => 'bool',
    ];
}
