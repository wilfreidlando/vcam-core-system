<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OperatorInvitation;
use Core\Identity\Domain\Port\OperatorInvitationRepository;
use DateTimeImmutable;

/**
 * Stores what the real repository stores — the hash, never the clear
 * token: every invitation is kept as a copy rebuilt with
 * `InvitationToken::restore()`, so a test reading it back gets exactly
 * what PostgreSQL would give (no clear value).
 */
final class InMemoryOperatorInvitationRepository implements OperatorInvitationRepository
{
    /** Number of row locks taken — lets a test prove the lock is taken. */
    public int $locks = 0;

    /** @var array<string, OperatorInvitation> */
    private array $invitations = [];

    public function save(OperatorInvitation $invitation): void
    {
        $this->invitations[$invitation->id] = new OperatorInvitation(
            id: $invitation->id,
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

    public function find(string $id): ?OperatorInvitation
    {
        return $this->invitations[$id] ?? null;
    }

    public function findByTokenHash(string $tokenHash): ?OperatorInvitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->token->hash() === $tokenHash) {
                return $invitation;
            }
        }

        return null;
    }

    public function lockByTokenHash(string $tokenHash): ?OperatorInvitation
    {
        $this->locks++;

        return $this->findByTokenHash($tokenHash);
    }

    public function findPendingByEmail(string $email, DateTimeImmutable $now): ?OperatorInvitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->email === $email && $invitation->isUsable($now)) {
                return $invitation;
            }
        }

        return null;
    }

    /**
     * @return list<OperatorInvitation>
     */
    public function all(): array
    {
        return array_values($this->invitations);
    }
}
