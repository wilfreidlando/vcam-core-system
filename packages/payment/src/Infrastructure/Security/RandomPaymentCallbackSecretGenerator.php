<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Security;

use Core\Payment\Domain\Port\PaymentCallbackSecretGenerator;

/**
 * `random_bytes()` — a CSPRNG, not `rand()`/`mt_rand()` — same rationale
 * and shape as `Core\Webhook\Infrastructure\Security\RandomWebhookSecretGenerator`
 * and `Core\Identity\Infrastructure\Security\RandomApiKeySecretGenerator`.
 */
final class RandomPaymentCallbackSecretGenerator implements PaymentCallbackSecretGenerator
{
    private const ENTROPY_BYTES = 32; // 256 bits

    public function generate(): string
    {
        return 'pcbsec_'.rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }
}
