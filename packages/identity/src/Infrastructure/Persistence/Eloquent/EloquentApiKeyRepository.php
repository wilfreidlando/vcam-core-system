<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Port\ApiKeyRepository;

final class EloquentApiKeyRepository implements ApiKeyRepository
{
    public function save(ApiKey $apiKey): void
    {
        ApiKeyRecord::query()->updateOrCreate(
            ['id' => $apiKey->id],
            [
                'project_id' => $apiKey->projectId,
                'environment' => $apiKey->environment,
                'prefix' => $apiKey->prefix,
                'key_hash' => $apiKey->keyHash(),
                'scopes' => $apiKey->scopes(),
                'revoked_at' => $apiKey->revokedAt(),
            ],
        );
    }

    public function find(string $id): ?ApiKey
    {
        $record = ApiKeyRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        $record = ApiKeyRecord::query()->where('prefix', $prefix)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(ApiKeyRecord $record): ApiKey
    {
        return new ApiKey(
            $record->id,
            $record->project_id,
            $record->environment,
            $record->prefix,
            $record->key_hash,
            $record->scopes,
            $record->revoked_at,
            $record->created_at,
        );
    }
}
