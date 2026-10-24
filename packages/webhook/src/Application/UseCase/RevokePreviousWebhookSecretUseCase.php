<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Support\UnitOfWork;
use Core\Webhook\Contracts\RevokePreviousWebhookSecret;
use Core\Webhook\Contracts\RevokePreviousWebhookSecretCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

final readonly class RevokePreviousWebhookSecretUseCase implements RevokePreviousWebhookSecret
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function revoke(RevokePreviousWebhookSecretCommand $command): bool
    {
        $revoked = $this->unitOfWork->transactional(function () use ($command): bool {
            $endpoint = $this->endpoints->findForUpdate($command->endpointId);
            if ($endpoint === null || $endpoint->projectId !== $command->projectId) {
                throw WebhookEndpointNotFound::withId($command->endpointId);
            }

            if ($endpoint->previousSecretAt($this->clock->now()) === null) {
                return false;
            }

            $this->endpoints->save($endpoint->withoutPreviousSecret());

            return true;
        });

        return $revoked === true;
    }
}
