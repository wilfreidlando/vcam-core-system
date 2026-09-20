<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * One Project's own application/merchant-account registration with one
 * payment provider (e.g. its MyCoolPay application: its own public key
 * shown to customers, its own private key used to sign/verify callbacks).
 *
 * Security review finding, expanded on user request: `MyCoolPayAdapter`
 * used to be constructed once with a single, platform-wide public/private
 * key pair from `config/providers.php` — every Project shared the same
 * MyCoolPay application. That's wrong for a multi-tenant Core: each
 * Project can (and, at scale, will) have its own MyCoolPay application,
 * all pointing their callback URL at this same Core, which must resolve
 * the right key pair per Project on the way out and per `application` on
 * the way back in.
 *
 * `publicKey` is not a secret (MyCoolPay's own docs: exposing it carries
 * no security risk) — it is the routing key an inbound webhook's
 * `application` field is matched against. `privateKey` is the actual
 * secret, used both to authenticate outbound calls that need it (payout,
 * balance — not yet implemented) and to verify inbound callback
 * signatures.
 */
final readonly class ProviderApplication
{
    public function __construct(
        public string $projectId,
        public string $providerCode,
        public string $publicKey,
        public string $privateKey,
    ) {}
}
