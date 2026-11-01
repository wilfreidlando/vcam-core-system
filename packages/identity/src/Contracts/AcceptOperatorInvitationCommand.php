<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0081 S-07 — the activation of a console account invitation.
 *
 * `token` is the clear activation token the invitee received; it is
 * hashed immediately and never stored. `password` is the password the
 * invitee **chooses**: no password is chosen by the system and none is
 * transmitted (ADR-0069 §4, ADR-0081 S-07).
 *
 * The email address is **not** part of the command: the account is
 * created with the email the invitation was issued to, so a holder of a
 * token cannot redirect the account to another address.
 */
final readonly class AcceptOperatorInvitationCommand
{
    public function __construct(
        public string $token,
        /** The display name of the account. */
        public string $name,
        public string $password,
    ) {}
}
