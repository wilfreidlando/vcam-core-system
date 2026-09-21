<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * Never carries the private key — this is a visibility read, not a
 * credential export. `publicKey` is safe to show in full (MyCoolPay's own
 * docs: it isn't secret, it's shown in every checkout link).
 */
final readonly class ProviderApplicationView
{
    public function __construct(
        public string $providerCode,
        public string $publicKey,
    ) {}
}
