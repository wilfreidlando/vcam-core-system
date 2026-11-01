<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use RuntimeException;

interface RotateWebhookSecret
{
    /**
     * @throws RuntimeException unknown endpoint or endpoint of another
     *                          project (same "not found"), overlap out of range
     */
    public function rotate(RotateWebhookSecretCommand $command): RotatedWebhookSecretView;
}
