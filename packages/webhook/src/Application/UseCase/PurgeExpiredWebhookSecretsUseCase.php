<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Webhook\Contracts\PurgeExpiredWebhookSecrets;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

/**
 * An expired previous secret already signs nothing (the sender checks the
 * expiry); erasing it keeps it from outliving its purpose at rest.
 */
final readonly class PurgeExpiredWebhookSecretsUseCase implements PurgeExpiredWebhookSecrets
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private Clock $clock,
    ) {}

    public function purge(): int
    {
        return $this->endpoints->purgeExpiredPreviousSecrets($this->clock->now());
    }
}
