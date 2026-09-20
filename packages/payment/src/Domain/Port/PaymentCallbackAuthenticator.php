<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Port;

/**
 * Verifies that an inbound provider webhook genuinely came from that
 * provider — the actual trust boundary `Presentation\Http\Controllers\MyCoolPayWebhookController`
 * checks before processing anything. A Domain port, not an Infrastructure
 * concrete type directly, so Presentation can depend on it without
 * reaching across layers (deptrac-layers.yaml: Presentation may not
 * depend on Infrastructure).
 */
interface PaymentCallbackAuthenticator
{
    public function isFromTrustedIp(?string $requestIp): bool;

    /**
     * @param array<string, mixed> $body
     */
    public function hasValidSignature(array $body, string $privateKey): bool;
}
