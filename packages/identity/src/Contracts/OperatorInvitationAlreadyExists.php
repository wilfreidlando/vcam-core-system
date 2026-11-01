<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0081 S-07 — an email address that already holds a still-activable
 * invitation cannot be invited again.
 *
 * The console answers **409**: the invitation in flight is the one to
 * use, and it expires on its own.
 */
final class OperatorInvitationAlreadyExists extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'operator-invitation-already-exists';

    public function __construct(
        public readonly string $email,
    ) {
        parent::__construct(sprintf(
            'An invitation for "%s" is already pending.',
            $email,
        ));
    }

    public static function forEmail(string $email): self
    {
        return new self($email);
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
