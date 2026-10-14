<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Webhook\Contracts\DeactivateWebhookEndpoint;
use Core\Webhook\Contracts\DeactivateWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class DeactivateWebhookEndpointUseCase implements DeactivateWebhookEndpoint
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
    ) {}

    public function deactivate(DeactivateWebhookEndpointCommand $command): void
    {
        $endpoint = $this->endpoints->find($command->endpointId);

        // Not found and "belongs to another project" get the same
        // exception — the anti-enumeration convention this codebase
        // already applies to Payment and Wallet reads.
        if ($endpoint === null
            || $endpoint->projectId !== $command->projectId) {
            throw WebhookEndpointNotFound::withId($command->endpointId);
        }

        $this->endpoints->save($endpoint->deactivated());
    }
}
