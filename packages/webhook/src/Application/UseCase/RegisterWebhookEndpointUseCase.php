<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Identifier;
use Core\Webhook\Contracts\RegisteredWebhookEndpointView;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Core\Webhook\Domain\Port\WebhookSecretGenerator;

final readonly class RegisterWebhookEndpointUseCase implements RegisterWebhookEndpoint
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookSecretGenerator $secretGenerator,
        private Identifier $identifier,
    ) {}

    public function register(RegisterWebhookEndpointCommand $command): RegisteredWebhookEndpointView
    {
        $secret = $this->secretGenerator->generate();

        $endpoint = new WebhookEndpoint(
            id: $this->identifier->generate(),
            projectId: $command->projectId,
            url: $command->url,
            secret: $secret,
            subscribedEventTypes: $command->events,
            active: true,
        );

        $this->endpoints->create($endpoint);

        return new RegisteredWebhookEndpointView(
            id: $endpoint->id,
            url: $endpoint->url,
            events: $endpoint->subscribedEventTypes,
            secret: $secret,
        );
    }
}
