<?php

declare(strict_types=1);

namespace Core\Webhook\Infrastructure\Persistence\Eloquent;

use Core\Webhook\Domain\Model\WebhookEndpoint;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;
use Illuminate\Support\Facades\Crypt;

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
        );
    }
}
