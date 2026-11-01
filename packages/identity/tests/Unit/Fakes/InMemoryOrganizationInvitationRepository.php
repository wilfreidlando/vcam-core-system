<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OrganizationInvitation;
use Core\Identity\Domain\Port\OrganizationInvitationRepository;
use DateTimeImmutable;

/**
 * Stores what the real repository stores — the hash, never the clear
 * token: every invitation is kept as a copy rebuilt with
 * `InvitationToken::restore()`, so a test reading it back gets exactly
 * what PostgreSQL would give (no clear value).
 */
final class InMemoryOrganizationInvitationRepository implements OrganizationInvitationRepository
{
    /** Number of row locks taken — lets a test prove the lock is taken. */
    public int $locks = 0;

    /** @var array<string, OrganizationInvitation> */
    private array $invitations = [];

    public function save(OrganizationInvitation $invitation): void
    {
        $this->invitations[$invitation->id] = new OrganizationInvitation(
            id: $invitation->id,
            organizationId: $invitation->organizationId,
            email: $invitation->email,
            role: $invitation->role,
            token: InvitationToken::restore(
                $invitation->token->hash(),
                $invitation->expiresAt(),
                $invitation->acceptedAt(),
                $invitation->revokedAt(),
            ),
            invitedBy: $invitation->invitedBy,
            createdAt: $invitation->createdAt,
        );
    }

    public function find(string $id): ?OrganizationInvitation
    {
        return $this->invitations[$id] ?? null;
    }

    public function findByTokenHash(string $tokenHash): ?OrganizationInvitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->token->hash() === $tokenHash) {
                return $invitation;
            }
        }

        return null;
    }

    public function lockByTokenHash(string $tokenHash): ?OrganizationInvitation
    {
        $this->locks++;

        return $this->findByTokenHash($tokenHash);
    }

    public function findPendingByEmail(string $organizationId, string $email, DateTimeImmutable $now): ?OrganizationInvitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->organizationId === $organizationId && $invitation->email === $email && $invitation->isUsable($now)) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * @return list<OrganizationInvitation>
     */
    public function all(): array
    {
        return array_values($this->invitations);
    }
}
