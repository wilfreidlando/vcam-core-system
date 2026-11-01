<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * `secret` is encrypted at rest (ADR-0040 §5) — plaintext only ever exists
 * in memory, decrypted here at read time and re-encrypted at write time.
 * Reversible on purpose: unlike an API key, the secret must be readable
 * again to sign every delivery.
 */
final class EloquentWebhookEndpointRepository implements WebhookEndpointRepository
{
    public function create(WebhookEndpoint $endpoint): void
    {
        WebhookEndpointRecord::query()->create($this->toAttributes($endpoint));
    }

    public function save(WebhookEndpoint $endpoint): void
    {
        WebhookEndpointRecord::query()->whereKey($endpoint->id)->update($this->toAttributes($endpoint));
    }

    public function find(string $id): ?WebhookEndpoint
    {
        $record = WebhookEndpointRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findForUpdate(string $id): ?WebhookEndpoint
    {
        $query = WebhookEndpointRecord::query()->whereKey($id);
        $query->getQuery()->lockForUpdate();
        $record = $query->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findActiveForProject(string $projectId): array
    {
        return array_values(
            WebhookEndpointRecord::query()
                ->where('project_id', $projectId)
                ->where('active', true)
                ->get()
                ->map(fn (WebhookEndpointRecord $record): WebhookEndpoint => $this->toDomain($record))
                ->all(),
        );
    }

    public function findAllForProject(string $projectId): array
    {
        return array_values(
            WebhookEndpointRecord::query()
                ->where('project_id', $projectId)
                ->get()
                ->map(fn (WebhookEndpointRecord $record): WebhookEndpoint => $this->toDomain($record))
                ->all(),
        );
    }

    public function countActiveByProject(string $projectId): int
    {
        return DB::table('webhook_endpoints')
            ->where('project_id', $projectId)
            ->where('active', true)
            ->count();
    }

    public function lockProject(string $projectId): void
    {
        // The anchor row is created on the fly: `insertOrIgnore` is an
        // INSERT ... ON CONFLICT DO NOTHING, so two concurrent
        // registrations of a project that has no anchor yet cannot both
        // create it — the second waits for the first, then does nothing.
        DB::table('webhook_project_locks')->insertOrIgnore([
            'project_id' => $projectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The row lock is held until the end of the transaction: a
        // concurrent registration waits here, and only then counts the
        // endpoints the first one created (audit 2026-10-29 #17 / G-12).
        DB::table('webhook_project_locks')
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first();
    }

    public function purgeExpiredPreviousSecrets(DateTimeImmutable $now): int
    {
        $query = WebhookEndpointRecord::query()->toBase();
        $query->whereNotNull('previous_secret_encrypted');
        $query->where('previous_secret_expires_at', '<=', $now);

        return $query->update(['previous_secret_encrypted' => null, 'previous_secret_expires_at' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'project_id' => $endpoint->projectId,
            'url' => $endpoint->url,
            'secret_encrypted' => Crypt::encryptString($endpoint->secret),
            'events' => $endpoint->subscribedEventTypes,
            'active' => $endpoint->active,
            'previous_secret_encrypted' => $endpoint->previousSecret === null ? null : Crypt::encryptString($endpoint->previousSecret),
            'previous_secret_expires_at' => $endpoint->previousSecretExpiresAt,
            'secret_rotated_at' => $endpoint->secretRotatedAt,
        ];
    }

    private function toDomain(WebhookEndpointRecord $record): WebhookEndpoint
    {
        return new WebhookEndpoint(
            id: $record->id,
            projectId: $record->project_id,
            url: $record->url,
            secret: Crypt::decryptString($record->secret_encrypted),
            subscribedEventTypes: array_values($record->events),
            active: $record->active,
            previousSecret: $record->previous_secret_encrypted === null ? null : Crypt::decryptString($record->previous_secret_encrypted),
            previousSecretExpiresAt: $record->previous_secret_expires_at,
            secretRotatedAt: $record->secret_rotated_at,
        );
    }
}
