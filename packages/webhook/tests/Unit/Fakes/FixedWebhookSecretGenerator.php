<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Webhook\Domain\Port\WebhookSecretGenerator;

final class FixedWebhookSecretGenerator implements WebhookSecretGenerator
{
    public function __construct(
        private readonly string $secret = 'whsec_fixed',
    ) {}

    public function generate(): string
    {
        return $this->secret;
    }
}
