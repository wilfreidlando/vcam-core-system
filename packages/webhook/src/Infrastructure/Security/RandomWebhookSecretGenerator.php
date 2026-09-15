<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Security;

use Core\Webhook\Domain\Port\WebhookSecretGenerator;

/**
 * `random_bytes()` — a CSPRNG, not `rand()`/`mt_rand()` — same rationale as
 * `Core\Identity\Infrastructure\Security\RandomApiKeySecretGenerator`.
 */
final class RandomWebhookSecretGenerator implements WebhookSecretGenerator
{
    private const ENTROPY_BYTES = 32; // 256 bits

    public function generate(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }
}
