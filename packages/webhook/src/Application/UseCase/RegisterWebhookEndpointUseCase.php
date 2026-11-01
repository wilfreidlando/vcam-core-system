<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use Core\Webhook\Contracts\RegisteredWebhookEndpointView;
use Core\Webhook\Contracts\RegisterWebhookEndpoint;
use Core\Webhook\Contracts\RegisterWebhookEndpointCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Core\Webhook\Domain\Port\WebhookSecretGenerator;

final readonly class RegisterWebhookEndpointUseCase implements RegisterWebhookEndpoint
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookSecretGenerator $secretGenerator,
        private Identifier $identifier,
        private UnitOfWork $unitOfWork,
        /**
         * Audit 2026-10-29 #17 / G-12 — the ceiling of **active** endpoints
         * a project may hold. Set by the platform (see
         * `WebhookServiceProvider`), on the model of ADR-0082 §1's ten API
         * keys per project.
         */
        private int $maxActiveEndpointsPerProject = 10,
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

        // The ceiling is counted inside the transaction, under the
        // project's lock: two concurrent registrations must not both pass
        // it (audit 2026-10-29 #17 / G-12 — the same discipline as the
        // API-key cap, ADR-0082 §1).
        $this->unitOfWork->transactional(function () use ($endpoint): void {
            $this->endpoints->lockProject($endpoint->projectId);

            if ($this->endpoints->countActiveByProject($endpoint->projectId) >= $this->maxActiveEndpointsPerProject) {
                throw TooManyWebhookEndpoints::forLimit($this->maxActiveEndpointsPerProject);
            }

            $this->endpoints->create($endpoint);
        });

        return new RegisteredWebhookEndpointView(
            id: $endpoint->id,
            url: $endpoint->url,
            events: $endpoint->subscribedEventTypes,
            secret: $secret,
        );
    }
}
