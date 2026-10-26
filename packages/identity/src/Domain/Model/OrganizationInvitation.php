<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0069 §4 — the invitation of one email address to join one
 * organization with one role. The credential itself lives in
 * `InvitationToken`; this model owns who was invited, for which
 * organization, with which role, and by whom.
 *
 * Nothing here throws: the model states *what is true*, the
 * Application layer decides *what is refused* (and raises the typed
 * refusals published in `Contracts/`).
 */
final class OrganizationInvitation
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $email,
        public readonly OrganizationRole $role,
        public readonly InvitationToken $token,
        public readonly string $invitedBy,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public function expiresAt(): DateTimeImmutable
    {
        return $this->token->expiresAt();
    }

    public function acceptedAt(): ?DateTimeImmutable
    {
        return $this->token->acceptedAt();
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->token->revokedAt();
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->token->isExpired($now);
    }

    public function isAccepted(): bool
    {
        return $this->token->isAccepted();
    }

    public function isRevoked(): bool
    {
        return $this->token->isRevoked();
    }

    /**
     * Still activable: not accepted, not revoked, not expired.
     */
    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->token->isUsable($now);
    }

    public function accept(DateTimeImmutable $at): void
    {
        $this->token->accept($at);
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->token->revoke($at);
    }
}
