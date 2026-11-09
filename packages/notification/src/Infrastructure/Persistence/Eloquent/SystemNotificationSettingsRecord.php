<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $project_id
 * @property array<int, mixed> $preferences the jsonb column, read back as it was written
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class SystemNotificationSettingsRecord extends Model
{
    use HasUuids;

    protected $table = 'notification_system_settings';

    protected $fillable = ['id', 'project_id', 'preferences'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['preferences' => 'array'];
    }
}
