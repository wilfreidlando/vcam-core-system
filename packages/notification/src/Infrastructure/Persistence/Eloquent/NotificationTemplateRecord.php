<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property string $key
 * @property string $channel
 * @property string $kind
 * @property string $template_status
 * @property int $version
 * @property array<string, mixed> $body
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class NotificationTemplateRecord extends Model
{
    use HasUuids;

    protected $table = 'notification_templates';

    protected $fillable = ['id', 'project_id', 'key', 'channel', 'kind', 'template_status', 'version', 'body'];

    protected $casts = [
        'version' => 'int',
        'body' => 'array',
    ];
}
