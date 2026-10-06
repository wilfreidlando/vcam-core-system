<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Core\Webhook\Contracts\WebhookDeliveryStats;
use Illuminate\Support\Facades\DB;

final class EloquentWebhookDeliveryStats implements WebhookDeliveryStats
{
    public function countsByStatus(?int $createdWithinSeconds = null): array
    {
        $rows = $createdWithinSeconds === null
            ? DB::select('SELECT status, COUNT(*) AS total FROM webhook_deliveries GROUP BY status')
            : DB::select(
                'SELECT status, COUNT(*) AS total FROM webhook_deliveries WHERE created_at >= NOW() - make_interval(secs => ?) GROUP BY status',
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
