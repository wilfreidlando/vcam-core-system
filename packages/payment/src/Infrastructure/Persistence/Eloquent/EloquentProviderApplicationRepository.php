<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Persistence\Eloquent;

use Core\Payment\Domain\Exception\ProviderApplicationPublicKeyAlreadyUsed;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `private_key_encrypted` uses `Crypt`, like `webhook_endpoints.secret_encrypted`:
 * it must be read back in plaintext to verify each inbound callback's
 * signature, so it cannot be hashed.
 */
final class EloquentProviderApplicationRepository implements ProviderApplicationRepository
{
    public function save(ProviderApplication $application): void
    {
        $owner = $this->findByProviderAndPublicKey($application->providerCode, $application->publicKey);
        if ($owner !== null && $owner->projectId !== $application->projectId) {
            throw new ProviderApplicationPublicKeyAlreadyUsed($owner->projectId);
        }

        try {
            // SAVEPOINT: a concurrent registration of the same public key
            // must not poison an outer transaction (same pattern as
            // EloquentPaymentRepository::create()).
            DB::transaction(function () use ($application): void {
                $existing = ProviderApplicationRecord::query()
                    ->where('project_id', $application->projectId)
                    ->where('provider_code', $application->providerCode)
                    ->first();

                $attributes = [
                    'public_key' => $application->publicKey,
                    'private_key_encrypted' => Crypt::encryptString($application->privateKey),
                    'label' => $application->label,
                ];

                if ($existing === null) {
                    ProviderApplicationRecord::query()->create([
                        'id' => $application->id ?? (string) Str::uuid7(),
                        'project_id' => $application->projectId,
                        'provider_code' => $application->providerCode,
                        ...$attributes,
                    ]);

                    return;
                }

                $existing->fill($attributes)->save();
            });
        } catch (UniqueConstraintViolationException) {
            $owner = $this->findByProviderAndPublicKey($application->providerCode, $application->publicKey);

            throw new ProviderApplicationPublicKeyAlreadyUsed($owner->projectId ?? 'inconnu');
        }
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

    public function find(string $id): ?ProviderApplication
    {
        $record = ProviderApplicationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function delete(string $id): void
    {
        ProviderApplicationRecord::query()->whereKey($id)->delete();
    }

    public function findAllByProject(string $projectId): array
    {
        // `orderBy` goes through `toBase()` — Larastan false positive on
        // forwarded builder calls (see EloquentWalletRepository::findByProject()).
        $base = ProviderApplicationRecord::query()->where('project_id', $projectId)->toBase();
        $base->orderBy('provider_code');

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();
        $records = ProviderApplicationRecord::hydrate($rows);

        $applications = [];
        foreach ($records as $record) {
            $applications[] = $this->toDomain($record);
        }

        return $applications;
    }

    private function toDomain(ProviderApplicationRecord $record): ProviderApplication
    {
        return new ProviderApplication(
            projectId: $record->project_id,
            providerCode: $record->provider_code,
            publicKey: $record->public_key,
            privateKey: Crypt::decryptString($record->private_key_encrypted),
            id: $record->id,
            label: $record->label,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
        );
    }
}
