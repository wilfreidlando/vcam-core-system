<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0081 S-07 — invites an email address to hold a console account.
 *
 * The invitation carries a one-shot activation token: 32 random bytes,
 * kept only as a SHA-256 hash, valid for 72 hours. The clear value is
 * returned **once**, in the creation response, and never again
 * (ADR-0081 S-07): it is neither stored, nor audited, nor logged.
 *
 * The account itself does not exist yet: it is created at activation,
 * with the password the invitee chooses. A platform operator therefore
 * never receives a password to transmit (ADR-0069 §4).
 *
 * This is a platform-reserved action: only a platform role may invite
 * a console account. The console checks the permission before calling
 * the port; the use case itself does not need a scope, since no
 * organization is involved.
 */
interface InviteOperator
{
    public function invite(
        InviteOperatorCommand $command,
        string $actor,
        ?string $correlationId,
    ): CreatedOperatorInvitation;
}
