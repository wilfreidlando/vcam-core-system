<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Webhook\Contracts\ActivateWebhookEndpoint;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class ActivateWebhookEndpointUseCase implements ActivateWebhookEndpoint
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
    ) {}

    public function activate(ActivateWebhookEndpointCommand $command): void
    {
        $endpoint = $this->endpoints->find($command->endpointId);

        // Not found and "belongs to another project" get the same
        // exception — the anti-enumeration convention this codebase
        // already applies to Payment and Wallet reads.
        if ($endpoint === null || $endpoint->projectId !== $command->projectId) {
            throw WebhookEndpointNotFound::withId($command->endpointId);
        }

        $this->endpoints->save($endpoint->activated());
    }
}
