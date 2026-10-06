<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Contracts\NotificationStats;
use Illuminate\Support\Facades\DB;

final class EloquentNotificationStats implements NotificationStats
{
    public function countsByStatus(?int $createdWithinSeconds = null): array
    {
        $rows = $createdWithinSeconds === null
            ? DB::select('SELECT status, COUNT(*) AS total FROM notifications GROUP BY status')
            : DB::select(
                'SELECT status, COUNT(*) AS total FROM notifications WHERE created_at >= NOW() - make_interval(secs => ?) GROUP BY status',
                [$createdWithinSeconds],
            );

        $counts = [];
        foreach ($rows as $row) {
            if (is_object($row) && isset($row->status, $row->total) && is_string($row->status) && is_numeric($row->total)) {
                $counts[$row->status] = (int) $row->total;
            }
        }

        return $counts;
    }
}
