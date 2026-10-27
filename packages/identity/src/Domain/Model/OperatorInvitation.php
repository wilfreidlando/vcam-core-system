<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use Core\Identity\OperatorRole;
use DateTimeImmutable;

/**
 * ADR-0081 S-07 — the invitation of one email address to hold a console
 * account. The credential itself lives in `InvitationToken`; this model
 * owns who was invited, with which platform role, and by whom.
 *
 * The difference with `OrganizationInvitation`: there is no organization
 * here. An organization invitation joins an existing account to an
 * organization with an organization role; this one creates the console
 * account itself, with a platform role (or without any).
 *
 * Nothing here throws: the model states *what is true*, the Application
 * layer decides *what is refused* (and raises the typed refusals
 * published in `Contracts/`).
 */
final class OperatorInvitation
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        /**
         * The platform role the invited account will carry. `null` means
         * an account without a platform role — it then belongs to
         * organizations only (ADR-0069 §1).
         */
        public readonly ?OperatorRole $role,
        public readonly InvitationToken $token,
        public readonly ?string $invitedBy,
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
