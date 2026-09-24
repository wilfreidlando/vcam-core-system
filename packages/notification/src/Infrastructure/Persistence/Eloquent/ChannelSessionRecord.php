<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $project_id
 * @property string $channel
 * @property string $session_ref
 * @property \Carbon\CarbonImmutable $created_at
 */
final class ChannelSessionRecord extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'notification_channel_sessions';

    protected $fillable = ['id', 'project_id', 'channel', 'session_ref', 'created_at'];
}
