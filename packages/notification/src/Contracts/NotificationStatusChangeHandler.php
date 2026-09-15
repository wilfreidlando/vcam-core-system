<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use Core\Notification\Contracts\Events\NotificationStatusChanged;

/**
 * Mirrors `Core\Payment\Contracts\PaymentStatusChangeHandler`: the entry
 * point a future adapter's webhook controller calls once it has
 * translated a raw provider callback into a canonical status change.
 */
interface NotificationStatusChangeHandler
{
    public function handle(NotificationStatusChanged $event): void;
}
