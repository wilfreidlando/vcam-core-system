<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §4 — an email address that already holds a still-activable
 * invitation in the same organization cannot be invited again.
 *
 * The console answers **409**: the invitation in flight is the one to
 * use, and it expires on its own.
 */
final class OrganizationInvitationAlreadyExists extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'invitation-already-exists';

    public function __construct(
        public readonly string $organizationId,
        public readonly string $email,
    ) {
        parent::__construct(sprintf(
            'An invitation for "%s" is already pending in organization %s.',
            $email,
            $organizationId,
        ));
    }

    public static function forEmail(string $organizationId, string $email): self
    {
        return new self($organizationId, $email);
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
