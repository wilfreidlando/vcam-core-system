<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §4 — the invited email already carries an active console
 * account, and the password presented is not that account's.
 *
 * An invitation never replaces the password of an existing account: the
 * token proves the invitee received the invitation, not that they hold
 * the account. Joining with an existing account therefore requires its
 * **current** password — which is verified, never overwritten. Without
 * this rule, anyone holding a token issued for someone else's email
 * would take over that person's console account (and every other
 * organization it belongs to).
 *
 * The console answers **422**; the route carries the login throttle.
 */
final class InvitationAccountPasswordInvalid extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'invitation-account-password-invalid';

    public function __construct()
    {
        parent::__construct(
            'A console account already exists for the invited email: enter its current password to join the organization. '.
            'An invitation never changes the password of an existing account.',
        );
    }

    public static function make(): self
    {
        return new self;
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
