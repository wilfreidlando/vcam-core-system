<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\IssuedApiKeyView;
use Core\Identity\Contracts\RotateApiKey as RotateApiKeyPort;
use Core\Identity\Contracts\RotateApiKeyCommand;
use Core\Identity\Domain\Exception\ApiKeyAlreadyRotated;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Exception\InvalidApiKeySettings;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Support\Clock;

/**
 * ADR-0082 §3 — guided rotation, without an outage: the replacement keeps
 * the old key's name, scopes and network restriction; the old key stays
 * valid for the grace period, then stops authenticating on its own.
 */
final readonly class RotateApiKey implements RotateApiKeyPort
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private IssueApiKey $issueApiKey,
        private Clock $clock,
    ) {}

    public function rotate(RotateApiKeyCommand $command, string $actor, ?string $correlationId): IssuedApiKeyView
    {
        if ($command->graceDays < RotateApiKeyCommand::MIN_GRACE_DAYS || $command->graceDays > RotateApiKeyCommand::MAX_GRACE_DAYS) {
            throw InvalidApiKeySettings::graceDays(RotateApiKeyCommand::MIN_GRACE_DAYS, RotateApiKeyCommand::MAX_GRACE_DAYS);
        }

        $old = $this->apiKeys->find($command->apiKeyId);
        if ($old === null || ($command->projectId !== null && $old->projectId !== $command->projectId)) {
            throw ApiKeyNotFound::withId($command->apiKeyId);
        }

        if (! $old->isUsableAt($this->clock->now())) {
            throw ApiKeyNotFound::withId($command->apiKeyId);
        }

        if ($old->isReplaced()) {
            throw ApiKeyAlreadyRotated::for($old->prefix);
        }

        // The replacement inherits everything but the expiry: an expiry is a
        // decision about one credential, not about the role it plays.
        return $this->issueApiKey->issueKey(
            new IssueApiKeyCommand(
                projectId: $old->projectId,
                scopes: $old->scopes(),
                name: $old->name,
                allowedCidrs: $old->allowedCidrs(),
            ),
            $actor,
            $correlationId,
            rotating: $old,
            graceDays: $command->graceDays,
        );
    }
}
