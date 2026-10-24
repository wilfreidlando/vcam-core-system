<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Webhook\Contracts\ListWebhookEndpoints;
use Core\Webhook\Contracts\ListWebhookEndpointsCommand;
use Core\Webhook\Contracts\WebhookEndpointView;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class ListWebhookEndpointsUseCase implements ListWebhookEndpoints
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private Clock $clock,
    ) {}

    public function list(ListWebhookEndpointsCommand $command): array
    {
        // Every endpoint, active or not — review finding: this used to
        // call `findActiveForProject()`, so a deactivated endpoint simply
        // vanished from the admin listing instead of showing as
        // "inactive" — making it impossible to find and reactivate one.
        // The actual fan-out decision (who receives the NEXT event)
        // still goes through `findActiveForProject()` elsewhere
        // (`HandleRelayedEventUseCase`), untouched by this change.
        $now = $this->clock->now();

        return array_map(
            static fn (WebhookEndpoint $endpoint): WebhookEndpointView => new WebhookEndpointView(
                id: $endpoint->id,
                url: $endpoint->url,
                events: $endpoint->subscribedEventTypes,
                active: $endpoint->active,
                previous_secret_expires_at: $endpoint->previousSecretAt($now) === null ? null : $endpoint->previousSecretExpiresAt?->format(DATE_ATOM),
            ),
            $this->endpoints->findAllForProject($command->projectId),
        );
    }
}
