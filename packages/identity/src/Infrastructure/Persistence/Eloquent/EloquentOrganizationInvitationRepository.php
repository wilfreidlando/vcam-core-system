<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OrganizationInvitation;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Port\OrganizationInvitationRepository;
use DateTimeImmutable;

final class EloquentOrganizationInvitationRepository implements OrganizationInvitationRepository
{
    public function save(OrganizationInvitation $invitation): void
    {
        OrganizationInvitationRecord::query()->updateOrCreate(
            ['id' => $invitation->id],
            [
                'organization_id' => $invitation->organizationId,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'token_hash' => $invitation->token->hash(),
                'invited_by' => $invitation->invitedBy,
                'expires_at' => $invitation->expiresAt(),
                'accepted_at' => $invitation->acceptedAt(),
                'revoked_at' => $invitation->revokedAt(),
                'created_at' => $invitation->createdAt,
            ],
        );
    }

    public function find(string $id): ?OrganizationInvitation
    {
        $record = OrganizationInvitationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByTokenHash(string $tokenHash): ?OrganizationInvitation
    {
        $record = OrganizationInvitationRecord::query()
            ->where('token_hash', $tokenHash)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function lockByTokenHash(string $tokenHash): ?OrganizationInvitation
    {
        // Must run inside the caller's open transaction (UnitOfWork): the
        // lock is released at commit/rollback, not by this call. Through
        // `toBase()` + `hydrate()` like the payment repository: Larastan
        // reports `lockForUpdate()` forwarded by Eloquent\Builder as a
        // dynamic call.
        $base = OrganizationInvitationRecord::query()
            ->where('token_hash', $tokenHash)
            ->toBase();
        $base->lockForUpdate();

        $row = $base->first();
        if ($row === null) {
            return null;
        }

        return $this->toDomain(OrganizationInvitationRecord::hydrate([(array) $row])->sole());
    }

    public function findPendingByEmail(string $organizationId, string $email, DateTimeImmutable $now): ?OrganizationInvitation
    {
        $record = OrganizationInvitationRecord::query()
            ->where('organization_id', $organizationId)
            ->where('email', $email)
            ->where('accepted_at', null)
            ->where('revoked_at', null)
            ->where('expires_at', '>', $now)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(OrganizationInvitationRecord $record): OrganizationInvitation
    {
        return new OrganizationInvitation(
            id: $record->id,
            organizationId: $record->organization_id,
            email: $record->email,
            role: OrganizationRole::from($record->role),
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
