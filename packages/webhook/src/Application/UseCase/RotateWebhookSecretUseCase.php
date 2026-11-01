<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Support\UnitOfWork;
use Core\Webhook\Contracts\RotatedWebhookSecretView;
use Core\Webhook\Contracts\RotateWebhookSecret;
use Core\Webhook\Contracts\RotateWebhookSecretCommand;
use Core\Webhook\Domain\Exception\InvalidWebhookSecretRotation;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Core\Webhook\Domain\Port\WebhookSecretGenerator;

/** ADR-0082 §5 — rotate a signing secret without breaking the receiver. */
final readonly class RotateWebhookSecretUseCase implements RotateWebhookSecret
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private WebhookSecretGenerator $secretGenerator,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function rotate(RotateWebhookSecretCommand $command): RotatedWebhookSecretView
    {
        if ($command->overlapHours < RotateWebhookSecretCommand::MIN_OVERLAP_HOURS || $command->overlapHours > RotateWebhookSecretCommand::MAX_OVERLAP_HOURS) {
            throw InvalidWebhookSecretRotation::overlap(RotateWebhookSecretCommand::MIN_OVERLAP_HOURS, RotateWebhookSecretCommand::MAX_OVERLAP_HOURS);
        }

        $now = $this->clock->now();
        $overlapEndsAt = $now->modify('+'.$command->overlapHours.' hours');
        $secret = $this->secretGenerator->generate();

        // Row lock: two concurrent rotations run one after the other, so the
        // secret each caller receives is the one actually stored (or kept as
        // previous), and a concurrent edit is never overwritten.
        $this->unitOfWork->transactional(function () use ($command, $secret, $now, $overlapEndsAt): void {
            $endpoint = $this->endpoints->findForUpdate($command->endpointId);
            if ($endpoint === null || $endpoint->projectId !== $command->projectId) {
                throw WebhookEndpointNotFound::withId($command->endpointId);
            }

            $this->endpoints->save($endpoint->withRotatedSecret($secret, $now, $overlapEndsAt));
        });

        return new RotatedWebhookSecretView($command->endpointId, $secret, $overlapEndsAt);
    }
}
