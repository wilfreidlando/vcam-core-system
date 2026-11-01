<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0069 §4 — the invitation accepted: the membership exists, and the
 * sign-in continues on the **existing** MFA flow (ADR-0044/0046).
 *
 * The challenge is exactly the one `POST /v1/operators/login` would have
 * returned for this account:
 *  - `challengePurpose = 'mfa_enrollment'` for an account without MFA
 *    (always the case for an account this invitation just created): hand
 *    `challengeToken` to `POST /v1/operators/mfa/enroll`, then confirm;
 *  - `challengePurpose = 'mfa'` for an existing account that already
 *    enrolled its TOTP: hand it to `POST /v1/operators/mfa/verify`.
 *
 * No session is opened here: a console session only ever comes out of a
 * verified TOTP code.
 */
final readonly class AcceptedOrganizationInvitation
{
    public function __construct(
        public string $operatorId,
        public string $organizationId,
        public string $role,
        public string $email,
        /**
         * True when this acceptance created the console account; false
         * when an existing account joined the organization.
         */
        public bool $accountCreated,
        /** `mfa_enrollment` or `mfa` — the same values as the login step. */
        public string $challengePurpose,
        /** The single-use, short-lived login challenge (5 minutes). */
        public string $challengeToken,
    ) {}
}
