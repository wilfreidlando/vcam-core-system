<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $notification_id
 * @property string $provider_code
 * @property string|null $provider_message_id
 * @property string $canonical_status
 * @property int $attempt
 * @property \Carbon\CarbonImmutable $created_at
 */
final class NotificationDeliveryRecord extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'notification_deliveries';

    protected $fillable = ['id', 'notification_id', 'provider_code', 'provider_message_id', 'canonical_status', 'attempt'];

    protected $casts = [
        'attempt' => 'int',
    ];
}
