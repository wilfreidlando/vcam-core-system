<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

interface PurgeExpiredWebhookSecrets
{
    /** @return int how many previous secrets were erased */
    public function purge(): int;
}
