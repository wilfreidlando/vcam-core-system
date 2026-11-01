<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\ApiKey;
use Core\Identity\Domain\Port\ApiKeyRepository;
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
                'prefix' => $apiKey->prefix,
                'key_hash' => $apiKey->keyHash(),
                'scopes' => $apiKey->scopes(),
                'revoked_at' => $apiKey->revokedAt(),
                'name' => $apiKey->name,
                'allowed_cidrs' => $apiKey->allowedCidrs(),
                'expires_at' => $apiKey->expiresAt(),
                'replaced_by_api_key_id' => $apiKey->replacedByApiKeyId(),
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

    public function countActiveByProject(string $projectId, DateTimeImmutable $now): int
    {
        $query = DB::table('api_keys');
        $query->where('project_id', $projectId);
        $query->whereNull('revoked_at');
        $query->where(static function (\Illuminate\Database\Query\Builder $q) use ($now): void {
            $q->whereNull('expires_at');
            $q->orWhere('expires_at', '>', $now);
        });

        return $query->count();
    }

    public function lockProject(string $projectId): void
    {
        // Transaction-scoped advisory lock, keyed on the project: released at
        // commit or rollback, never held across requests.
        DB::select("SELECT pg_advisory_xact_lock(hashtextextended('api_keys:' || ?, 0))", [$projectId]);
    }

    public function delete(string $id): void
    {
        ApiKeyRecord::query()->whereKey($id)->delete();
    }

    public function touchLastUsed(string $id, DateTimeImmutable $at, ?string $ip = null): void
    {
        DB::update(
            'UPDATE api_keys SET last_used_at = ?, last_used_ip = COALESCE(?::inet, last_used_ip)
             WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ? OR last_used_ip IS DISTINCT FROM COALESCE(?::inet, last_used_ip))',
            [$at, $ip, $id, $at->modify('-60 seconds'), $ip],
        );
    }

    private function toDomain(ApiKeyRecord $record): ApiKey
    {
        return new ApiKey(
            $record->id,
            $record->project_id,
            $record->prefix,
            $record->key_hash,
            $record->scopes,
            $record->revoked_at,
            $record->created_at,
            $record->last_used_at,
            name: $record->name,
            allowedCidrs: $record->allowed_cidrs,
            expiresAt: $record->expires_at,
            replacedByApiKeyId: $record->replaced_by_api_key_id,
            lastUsedIp: $record->last_used_ip,
        );
    }
}
