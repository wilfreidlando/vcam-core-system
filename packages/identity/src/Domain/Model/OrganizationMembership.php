<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0069 §1 — a console account's membership in one organization, with
 * the role it holds there. Revoking keeps the row (the audit trail needs
 * it); the unique index only covers active memberships.
 */
final class OrganizationMembership
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $operatorId,
        public OrganizationRole $role,
        public readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $revokedAt = null,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function revoke(DateTimeImmutable $at): void
    {
        $this->revokedAt ??= $at;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
