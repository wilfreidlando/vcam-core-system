<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

use DateTimeImmutable;

/**
 * `secret` exists only here, to be shown once (S-07); the previous secret is
 * never returned.
 */
final readonly class RotatedWebhookSecretView
{
    public function __construct(
        public string $endpointId,
        public string $secret,
        public DateTimeImmutable $previousSecretExpiresAt,
    ) {}
}
