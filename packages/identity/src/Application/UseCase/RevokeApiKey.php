<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\RevokeApiKey as RevokeApiKeyPort;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Exception\InvalidApiKeySettings;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * ADR-0082 §3 — emergency revocation: immediate, at any time (even during a
 * rotation's grace period), with a mandatory reason, audited.
 */
final readonly class RevokeApiKey implements RevokeApiKeyPort
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function revoke(RevokeApiKeyCommand $command, string $actor, ?string $correlationId): void
    {
        $reason = trim($command->reason);
        if ($reason === '') {
            throw InvalidApiKeySettings::reason();
        }

        $apiKey = $this->apiKeys->find($command->apiKeyId);
        if ($apiKey === null || ($command->projectId !== null && $apiKey->projectId !== $command->projectId)) {
            throw ApiKeyNotFound::withId($command->apiKeyId);
        }

        // ADR-0060: revoking deletes the key — an application using it is
        // rejected at its next request. The audit trail keeps the record.
        $this->unitOfWork->transactional(function () use ($apiKey, $reason, $actor, $correlationId): void {
            // Same lock as issuance and rotation: a rotation running at the
            // same moment either finishes first (its replacement survives,
            // this key goes) or sees this key gone — never re-inserts it.
            $this->apiKeys->lockProject($apiKey->projectId);
            if ($this->apiKeys->find($apiKey->id) === null) {
                throw ApiKeyNotFound::withId($apiKey->id);
            }

            $this->apiKeys->delete($apiKey->id);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'api_key.revoked',
                resource: 'api_key:'.$apiKey->id,
                before: ['project_id' => $apiKey->projectId, 'prefix' => $apiKey->prefix, 'name' => $apiKey->name, 'scopes' => $apiKey->scopes()],
                after: null,
                context: ['reason' => mb_substr($reason, 0, 500)],
                correlationId: $correlationId,
                occurredAt: $this->clock->now(),
                projectId: $apiKey->projectId,
            ));
        });
    }
}
