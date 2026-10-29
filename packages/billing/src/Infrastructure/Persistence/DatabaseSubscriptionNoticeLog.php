<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence;

use Core\Billing\Application\Port\SubscriptionNoticeLog;
use Illuminate\Support\Facades\DB;

final class DatabaseSubscriptionNoticeLog implements SubscriptionNoticeLog
{
    public function firstTime(string $subscriptionId, string $notice): bool
    {
        return DB::table('subscription_notices')->insertOrIgnore([
            'subscription_id' => $subscriptionId,
            'notice' => $notice,
        ]) === 1;
    }
}
