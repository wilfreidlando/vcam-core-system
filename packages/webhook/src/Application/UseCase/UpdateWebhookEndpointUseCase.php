<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Webhook\Contracts\UpdateWebhookEndpoint;
use Core\Webhook\Contracts\UpdateWebhookEndpointCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class UpdateWebhookEndpointUseCase implements UpdateWebhookEndpoint
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
    ) {}

    public function update(UpdateWebhookEndpointCommand $command): WebhookEndpointView
    {
        $endpoint = $this->endpoints->find($command->endpointId);

        // Not found and "belongs to another project" get the same
        // exception — the anti-enumeration convention this codebase
        // already applies to Payment and Wallet reads.
        if ($endpoint === null || $endpoint->projectId !== $command->projectId) {
            throw WebhookEndpointNotFound::withId($command->endpointId);
        }

        $updated = $endpoint->withUrlAndEvents($command->url, $command->events);
        $this->endpoints->save($updated);

        return new WebhookEndpointView(
            id: $updated->id,
            url: $updated->url,
            events: $updated->subscribedEventTypes,
            active: $updated->active,
        );
    }
}
