<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $channel
 * @property string $to_party_ref
 * @property string $template_key
 * @property string $idempotency_key
 * @property string $status
 * @property string|null $provider_code
 * @property string|null $provider_message_id
 * @property string|null $error_message
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class NotificationRecord extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $fillable = [
        'id', 'project_id', 'channel', 'to_party_ref', 'template_key',
        'idempotency_key', 'status', 'provider_code', 'provider_message_id', 'error_message',
    ];
}
