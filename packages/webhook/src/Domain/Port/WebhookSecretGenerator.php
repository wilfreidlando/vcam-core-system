<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Port;

interface WebhookSecretGenerator
{
    public function generate(): string;
}
