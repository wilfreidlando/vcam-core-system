<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\RevokeApiKey as RevokeApiKeyPort;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Support\Clock;

final readonly class RevokeApiKey implements RevokeApiKeyPort
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function revoke(RevokeApiKeyCommand $command, string $actor, ?string $correlationId): void
    {
        $apiKey = $this->apiKeys->find($command->apiKeyId);
        if ($apiKey === null || ($command->projectId !== null && $apiKey->projectId !== $command->projectId)) {
            throw ApiKeyNotFound::withId($command->apiKeyId);
        }

        // ADR-0060: revoking deletes the key — an application using it is
        // rejected at its next request. The audit trail keeps the record.
        $this->apiKeys->delete($apiKey->id);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'api_key.revoked',
            resource: 'api_key:'.$apiKey->id,
            before: ['project_id' => $apiKey->projectId, 'environment' => $apiKey->environment->value, 'prefix' => $apiKey->prefix],
            after: null,
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        ));
    }
}
