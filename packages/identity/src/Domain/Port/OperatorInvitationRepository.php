<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\OperatorInvitation;
use DateTimeImmutable;

/**
 * The persistence of the console account invitations (ADR-0081 S-07).
 *
 * The token is looked up by its **hash**, never by its clear value, and
 * the row that carries it is locked before it is consumed — two
 * simultaneous activations must not both create an account.
 */
interface OperatorInvitationRepository
{
    public function save(OperatorInvitation $invitation): void;

    public function find(string $id): ?OperatorInvitation;

    public function findByTokenHash(string $tokenHash): ?OperatorInvitation;

    /**
     * The invitation, locked. Must run inside the caller's open
     * transaction: the lock is released at commit/rollback, not by this
     * call.
     */
    public function lockByTokenHash(string $tokenHash): ?OperatorInvitation;

    /**
     * The invitation still activable for this email, if any.
     */
    public function findPendingByEmail(string $email, DateTimeImmutable $now): ?OperatorInvitation;
}
