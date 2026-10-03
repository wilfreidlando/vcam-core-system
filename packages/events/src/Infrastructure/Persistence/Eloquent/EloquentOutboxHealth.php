<?php

declare(strict_types=1);

namespace Core\Events\Infrastructure\Persistence\Eloquent;

use Core\Events\Contracts\OutboxHealth;
use Core\Events\Contracts\OutboxHealthView;
use Illuminate\Support\Facades\DB;

final class EloquentOutboxHealth implements OutboxHealth
{
    public function snapshot(): OutboxHealthView
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS unpublished, COALESCE(EXTRACT(EPOCH FROM (NOW() - MIN(created_at))), 0) AS oldest_age
             FROM event_outbox WHERE published_at IS NULL'
        );

        $unpublished = is_object($row) && isset($row->unpublished) && is_numeric($row->unpublished) ? (int) $row->unpublished : 0;
        $oldest = is_object($row) && isset($row->oldest_age) && is_numeric($row->oldest_age) ? (int) $row->oldest_age : 0;

        return new OutboxHealthView($unpublished, max(0, $oldest));
    }
}
