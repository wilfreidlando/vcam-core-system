<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0081 S-07 — consumes an activation token: the invited email
 * gets its console account, with the password it chooses and the
 * platform role the invitation carried.
 *
 * The sign-in continues on the **existing** MFA flow (ADR-0044/0046):
 * the challenge returned is exactly the one `LoginOperator` would issue
 * for the account — `mfa_enrollment` for an account without MFA (always
 * the case for a new one). No session is opened here, and no second
 * mechanism exists.
 */
interface AcceptOperatorInvitation
{
    public function accept(AcceptOperatorInvitationCommand $command): AcceptedOperatorInvitation;
}
