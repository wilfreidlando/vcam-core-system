<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Illuminate\Support\Facades\Crypt;

/**
 * `privateKey` is encrypted at rest, decrypted only in memory at read
 * time (same pattern as `EloquentWebhookEndpointRepository` — ADR-0040
 * §5). `save()` is an upsert keyed on `(project_id, provider_code)`: the
 * CLI bootstrap command re-registering a Project's application (key
 * rotation) simply calls it again.
 */
final class EloquentProviderApplicationRepository implements ProviderApplicationRepository
{
    public function save(ProviderApplication $application): void
    {
        ProviderApplicationRecord::query()->updateOrCreate(
            [
                'project_id' => $application->projectId,
                'provider_code' => $application->providerCode,
            ],
            [
                'public_key' => $application->publicKey,
                'private_key_encrypted' => Crypt::encryptString($application->privateKey),
            ],
        );
    }

    public function findByProjectAndProvider(string $projectId, string $providerCode): ?ProviderApplication
    {
        $record = ProviderApplicationRecord::query()
            ->where('project_id', $projectId)
            ->where('provider_code', $providerCode)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByProviderAndPublicKey(string $providerCode, string $publicKey): ?ProviderApplication
    {
        $record = ProviderApplicationRecord::query()
            ->where('provider_code', $providerCode)
            ->where('public_key', $publicKey)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(ProviderApplicationRecord $record): ProviderApplication
    {
        return new ProviderApplication(
            projectId: $record->project_id,
            providerCode: $record->provider_code,
            publicKey: $record->public_key,
            privateKey: Crypt::decryptString($record->private_key_encrypted),
        );
    }
}
