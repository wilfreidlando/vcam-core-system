<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use RuntimeException;

interface RevokePreviousWebhookSecret
{
    /**
     * @return bool false when there was no previous secret left to revoke
     *
     * @throws RuntimeException unknown endpoint or endpoint of another project
     */
    public function revoke(RevokePreviousWebhookSecretCommand $command): bool;
}
