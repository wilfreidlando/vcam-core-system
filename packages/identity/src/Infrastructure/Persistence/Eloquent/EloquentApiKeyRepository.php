<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Support\Environment;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

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

    public function findAllByProject(string $projectId): array
    {
        // `orderBy` is forwarded by Eloquent\Builder to the underlying
        // query builder rather than declared on it directly; going
        // through `toBase()` first avoids a Larastan false positive
        // ("dynamic call to static method") — same pattern as
        // EloquentWebhookDeliveryRepository::findByStatusPaginated().
        $base = ApiKeyRecord::query()->where('project_id', $projectId)->toBase();
        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();
        $records = ApiKeyRecord::hydrate($rows);

        $items = [];
        foreach ($records as $record) {
            $items[] = $this->toDomain($record);
        }

        return $items;
    }

    public function findByProjectAndEnvironment(string $projectId, Environment $environment): ?ApiKey
    {
        $record = ApiKeyRecord::query()
            ->where('project_id', $projectId)
            ->where('environment', $environment->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function delete(string $id): void
    {
        ApiKeyRecord::query()->whereKey($id)->delete();
    }

    public function touchLastUsed(string $id, DateTimeImmutable $at): void
    {
        DB::update(
            'UPDATE api_keys SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [$at, $id, $at->modify('-60 seconds')],
        );
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
            $record->last_used_at,
        );
    }
}
