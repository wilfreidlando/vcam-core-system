<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\OrganizationInvitation;
use DateTimeImmutable;

/**
 * ADR-0069 §4 — persistence of the organization invitations.
 *
 * The clear token is never stored: the repository only ever sees the
 * SHA-256 hash, which is also the lookup key when an invitee presents
 * their token.
 */
interface OrganizationInvitationRepository
{
    public function save(OrganizationInvitation $invitation): void;

    public function find(string $id): ?OrganizationInvitation;

    /**
     * The invitation a clear token resolves to — the hash is the key.
     */
    public function findByTokenHash(string $tokenHash): ?OrganizationInvitation;

    /**
     * Same lookup, but the row is **locked** until the surrounding
     * transaction ends (`SELECT … FOR UPDATE`). It is what makes the
     * token single-use under concurrency: of two simultaneous
     * acceptances, the second waits for the first, then sees the
     * invitation already accepted. Must be called inside a transaction.
     */
    public function lockByTokenHash(string $tokenHash): ?OrganizationInvitation;

    /**
     * A still-activable invitation for this email in this organization
     * (not accepted, not revoked, not expired). It is what makes a
     * second invitation for the same email refused.
     */
    public function findPendingByEmail(string $organizationId, string $email, DateTimeImmutable $now): ?OrganizationInvitation;
}
