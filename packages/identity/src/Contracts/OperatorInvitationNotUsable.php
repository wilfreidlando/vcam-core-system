<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0081 S-07 — the invitation exists but can no longer be used: it
 * has expired (72 h), it has already been accepted, or it has been
 * revoked.
 *
 * The console answers **409** — the request is a conflict with the
 * current state of the invitation, not a malformed request.
 */
final class OperatorInvitationNotUsable extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'operator-invitation-not-usable';

    /**
     * @param string $reason `expired`, `already_used` or `revoked` — the
     *                       machine-readable reason of the refusal.
     */
    public function __construct(
        public readonly string $invitationId,
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function expired(string $invitationId): self
    {
        return new self($invitationId, 'expired', 'This invitation has expired: an activation token is valid for 72 hours.');
    }

    public static function alreadyAccepted(string $invitationId): self
    {
        return new self($invitationId, 'already_used', 'This invitation has already been accepted: an activation token is usable once.');
    }

    public static function revoked(string $invitationId): self
    {
        return new self($invitationId, 'revoked', 'This invitation has been revoked.');
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
