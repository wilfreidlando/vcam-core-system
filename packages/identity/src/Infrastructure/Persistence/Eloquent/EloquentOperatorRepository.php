<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Illuminate\Support\Facades\Crypt;

/**
 * `mfa_secret_encrypted` is encrypted at rest, not hashed — reversible on
 * purpose, since TOTP verification needs the raw secret back (same
 * reasoning as `EloquentWebhookEndpointRepository`'s `secret_encrypted`).
 */
final class EloquentOperatorRepository implements OperatorRepository
{
    public function create(Operator $operator): void
    {
        OperatorRecord::query()->create($this->toAttributes($operator));
    }

    public function save(Operator $operator): void
    {
        OperatorRecord::query()->whereKey($operator->id)->update($this->toAttributes($operator));
    }

    public function find(string $id): ?Operator
    {
        $record = OperatorRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByEmail(string $email): ?Operator
    {
        $record = OperatorRecord::query()->where('email', $email)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(Operator $operator): array
    {
        return [
            'id' => $operator->id,
            'email' => $operator->email,
            'name' => $operator->name,
            'password_hash' => $operator->passwordHash(),
            'role' => $operator->role->value,
            'mfa_secret_encrypted' => $operator->mfaSecret() === null ? null : Crypt::encryptString($operator->mfaSecret()),
            'mfa_enabled' => $operator->mfaEnabled(),
            'mfa_last_verified_timestep' => $operator->mfaLastVerifiedTimestep(),
            'revoked_at' => $operator->revokedAt(),
        ];
    }

    private function toDomain(OperatorRecord $record): Operator
    {
        return new Operator(
            id: $record->id,
            email: $record->email,
            name: $record->name,
            passwordHash: $record->password_hash,
            role: OperatorRole::from($record->role),
            mfaSecret: $record->mfa_secret_encrypted === null ? null : Crypt::decryptString($record->mfa_secret_encrypted),
            mfaEnabled: $record->mfa_enabled,
            mfaLastVerifiedTimestep: $record->mfa_last_verified_timestep,
            revokedAt: $record->revoked_at,
            createdAt: $record->created_at,
        );
    }
}
