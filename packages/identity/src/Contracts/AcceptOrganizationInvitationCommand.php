<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0069 §4 — the activation of an invitation.
 *
 * `token` is the clear activation token the invitee received; it is
 * hashed immediately and never stored. `password` is:
 *  - for an email without a console account, the password the invitee
 *    **chooses** at activation: no password is chosen by the system and
 *    none is transmitted (ADR-0069 §4, ADR-0081 S-07);
 *  - for an email that already carries an active account, that
 *    account's **current** password — verified, never replaced: an
 *    invitation must not let its holder take over an existing account.
 *
 * The email address is **not** part of the command: the account is
 * created with the email the invitation was issued to, so a holder of a
 * token cannot redirect the membership to another address.
 */
final readonly class AcceptOrganizationInvitationCommand
{
    public function __construct(
        public string $token,
        /** The display name of a new account; ignored for an existing one. */
        public string $name,
        public string $password,
    ) {}
}
