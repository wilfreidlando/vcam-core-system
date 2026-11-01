<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ApiKeySummaryView;
use Core\Identity\Contracts\ListApiKeys as ListApiKeysPort;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;

final readonly class ListApiKeys implements ListApiKeysPort
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
        private Clock $clock,
    ) {}

    public function list(ListApiKeysCommand $command, AccessScope $scope): array
    {
        $now = $this->clock->now();

        // ADR-0069 §2 — a project outside the caller's organizations has
        // no key to list.
        if (! $scope->allowsProject($command->projectId)) {
            return [];
        }

        return array_map(
            static fn (ApiKey $apiKey): ApiKeySummaryView => new ApiKeySummaryView(
                id: $apiKey->id,
                projectId: $apiKey->projectId,
                prefix: $apiKey->prefix,
                scopes: $apiKey->scopes(),
                revoked: $apiKey->isRevoked(),
                createdAt: $apiKey->createdAt,
                lastUsedAt: $apiKey->lastUsedAt,
                name: $apiKey->name,
                allowedCidrs: $apiKey->allowedCidrs(),
                expiresAt: $apiKey->expiresAt(),
                replacedByApiKeyId: $apiKey->replacedByApiKeyId(),
                lastUsedIp: $apiKey->lastUsedIp,
                active: $apiKey->isUsableAt($now),
            ),
            $this->apiKeys->findAllByProject($command->projectId),
        );
    }
}
