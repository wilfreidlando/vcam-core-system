<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\RevokeApiKeyCommand;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Support\Clock;

final readonly class RevokeApiKey
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(RevokeApiKeyCommand $command, string $actor, ?string $correlationId): void
    {
        $apiKey = $this->apiKeys->find($command->apiKeyId)
            ?? throw ApiKeyNotFound::withId($command->apiKeyId);

        $wasAlreadyRevoked = $apiKey->isRevoked();
        $now = $this->clock->now();

        $apiKey->revoke($now);
        $this->apiKeys->save($apiKey);

        if ($wasAlreadyRevoked) {
            return;
        }

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'api_key.revoked',
            resource: 'api_key:'.$apiKey->id,
            before: ['revoked_at' => null],
            after: ['revoked_at' => $now->format(DATE_ATOM)],
            context: [],
            correlationId: $correlationId,
            occurredAt: $now,
        ));
    }
}
