<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\UnitOfWork;
use Core\Webhook\Contracts\ActivateWebhookEndpoint;
use Core\Webhook\Contracts\ActivateWebhookEndpointCommand;
use Core\Webhook\Contracts\TooManyWebhookEndpoints;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class ActivateWebhookEndpointUseCase implements ActivateWebhookEndpoint
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private UnitOfWork $unitOfWork,
        /**
         * Audit 2026-10-29 #17 / G-12 — the ceiling of **active** endpoints
         * a project may hold. Re-activating an endpoint adds one to that
         * count, so it is refused at the ceiling exactly like a
         * registration is; without it the ceiling would be a door any
         * operator could walk around by deactivating first.
         */
        private int $maxActiveEndpointsPerProject = 10,
    ) {}

    public function activate(ActivateWebhookEndpointCommand $command): void
    {
        // The project's lock is taken first, then the endpoint's row: the
        // same order everywhere, so two activations never deadlock.
        $this->unitOfWork->transactional(function () use ($command): void {
            $this->endpoints->lockProject($command->projectId);

            $endpoint = $this->endpoints->findForUpdate($command->endpointId);

            // Not found and "belongs to another project" get the same
            // exception — the anti-enumeration convention this codebase
            // already applies to Payment and Wallet reads.
            if ($endpoint === null || $endpoint->projectId !== $command->projectId) {
                throw WebhookEndpointNotFound::withId($command->endpointId);
            }

            // Already active: the endpoint is already counted, so this
            // changes nothing and must not be refused by the ceiling.
            if ($endpoint->active) {
                return;
            }

            if ($this->endpoints->countActiveByProject($endpoint->projectId) >= $this->maxActiveEndpointsPerProject) {
                throw TooManyWebhookEndpoints::forLimit($this->maxActiveEndpointsPerProject);
            }

            $this->endpoints->save($endpoint->activated());
        });
    }
}
