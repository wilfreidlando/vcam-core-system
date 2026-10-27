<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OperatorInvitation;
use Core\Identity\Domain\Port\OperatorInvitationRepository;
use Core\Identity\OperatorRole;
use DateTimeImmutable;

final class EloquentOperatorInvitationRepository implements OperatorInvitationRepository
{
    public function save(OperatorInvitation $invitation): void
    {
        OperatorInvitationRecord::query()->updateOrCreate(
            ['id' => $invitation->id],
            [
                'email' => $invitation->email,
                'role' => $invitation->role?->value,
                'token_hash' => $invitation->token->hash(),
                'invited_by' => $invitation->invitedBy,
                'expires_at' => $invitation->expiresAt(),
                'accepted_at' => $invitation->acceptedAt(),
                'revoked_at' => $invitation->revokedAt(),
                'created_at' => $invitation->createdAt,
            ],
        );
    }

    public function find(string $id): ?OperatorInvitation
    {
        $record = OperatorInvitationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByTokenHash(string $tokenHash): ?OperatorInvitation
    {
        $record = OperatorInvitationRecord::query()
            ->where('token_hash', $tokenHash)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function lockByTokenHash(string $tokenHash): ?OperatorInvitation
    {
        // Must run inside the caller's open transaction (UnitOfWork): the
        // lock is released at commit/rollback, not by this call. Through
        // `toBase()` + `hydrate()` like the other repositories:
        // Larastan reports `lockForUpdate()` forwarded by Eloquent's
        // builder as a dynamic call.
        $base = OperatorInvitationRecord::query()
            ->where('token_hash', $tokenHash)
            ->toBase();
        $base->lockForUpdate();

        $row = $base->first();
        if ($row === null) {
            return null;
        }

        return $this->toDomain(OperatorInvitationRecord::hydrate([(array) $row])->sole());
    }

    public function findPendingByEmail(string $email, DateTimeImmutable $now): ?OperatorInvitation
    {
        $record = OperatorInvitationRecord::query()
            ->where('email', $email)
            ->where('accepted_at', null)
            ->where('revoked_at', null)
            ->where('expires_at', '>', $now)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(OperatorInvitationRecord $record): OperatorInvitation
    {
        return new OperatorInvitation(
            id: $record->id,
            email: $record->email,
            role: $record->role === null ? null : OperatorRole::from($record->role),
            token: InvitationToken::restore(
                $record->token_hash,
                $record->expires_at,
                $record->accepted_at,
                $record->revoked_at,
            ),
            invitedBy: $record->invited_by,
            createdAt: $record->created_at,
        );
    }
}
