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

    public function findAll(int $page, int $perPage): array
    {
        // `orderBy`/`skip`/`take`/`count` are forwarded by Eloquent\Builder
        // to the underlying query builder rather than declared on it
        // directly; going through `toBase()` first avoids a Larastan false
        // positive ("dynamic call to static method") on those forwarded
        // calls, and `OperatorRecord::hydrate()` restores the casts after.
        // Tiebreaker on the primary key: two rows can share a created_at.
        $base = OperatorRecord::query()->toBase();

        $total = (clone $base)->count();

        $base->orderBy('created_at', 'desc');
        $base->orderBy('id', 'desc');
        $base->skip(($page - 1) * $perPage);
        $base->take($perPage);

        $rows = $base->get()->map(static fn (object $row): array => (array) $row)->all();

        $records = OperatorRecord::hydrate($rows);

        $operators = [];
        foreach ($records as $record) {
            $operators[] = $this->toDomain($record);
        }

        return ['items' => $operators, 'total' => $total];
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
