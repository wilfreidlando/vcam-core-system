<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use DateTimeImmutable;

/**
 * The invitation just created — and the **only** moment the clear
 * activation token exists (ADR-0081 S-07).
 *
 * `token` is the clear value: it is never stored, never logged and
 * never retrievable again. The console shows it once, to the platform
 * operator who invited, and hands it to the invitee out of band.
 */
final readonly class CreatedOperatorInvitation
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $role,
        /**
         * The clear activation token — returned once, at creation.
         */
        public ?string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
