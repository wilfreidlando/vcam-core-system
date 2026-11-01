<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * ADR-0081 S-07 — the invitation of one email address to hold a console
 * account.
 *
 * The command carries no password: the account's password is chosen by
 * the invitee, at activation. The system never chooses one and never
 * transmits one (ADR-0069 §4).
 *
 * `role` is the platform role the account will carry; an empty role
 * means an account without a platform role (ADR-0069 §1), which then
 * lives in its organizations' memberships only.
 */
final readonly class InviteOperatorCommand
{
    public function __construct(
        public string $email,
        public string $role,
        /**
         * The console account that issues the invitation; `null` when
         * the invitation comes from the CLI, which is not a console
         * account. It is NOT the audit actor: the actor is a free-form
         * string ('operator:<uuid>', 'service:cli'), this is the account.
         */
        public ?string $invitedBy = null,
    ) {}
}
