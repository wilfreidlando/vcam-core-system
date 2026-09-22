<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\ApiKeySummaryView;
use Core\Identity\Contracts\ListApiKeys as ListApiKeysPort;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Port\ApiKeyRepository;

final readonly class ListApiKeys implements ListApiKeysPort
{
    public function __construct(
        private ApiKeyRepository $apiKeys,
    ) {}

    public function list(ListApiKeysCommand $command): array
    {
        return array_map(
            static fn (ApiKey $apiKey): ApiKeySummaryView => new ApiKeySummaryView(
                id: $apiKey->id,
                projectId: $apiKey->projectId,
                environment: $apiKey->environment,
                prefix: $apiKey->prefix,
                scopes: $apiKey->scopes(),
                revoked: $apiKey->isRevoked(),
                createdAt: $apiKey->createdAt,
            ),
            $this->apiKeys->findAllByProject($command->projectId),
        );
    }
}
