<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class ListWebhookEndpointsUseCase implements ListWebhookEndpoints
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
    ) {}

    public function list(ListWebhookEndpointsCommand $command): array
    {
        return array_map(
            static fn (WebhookEndpoint $endpoint): WebhookEndpointView => new WebhookEndpointView(
                id: $endpoint->id,
                url: $endpoint->url,
                events: $endpoint->subscribedEventTypes,
                active: $endpoint->active,
            ),
            $this->endpoints->findActiveForProject($command->projectId),
        );
    }
}
