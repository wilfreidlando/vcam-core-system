<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/** ADR-0082 §5 — end the overlap now: only the new secret signs. */
final readonly class RevokePreviousWebhookSecretCommand
{
    public function __construct(
        public string $projectId,
        public string $endpointId,
    ) {}
}
