<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §4 — the password the invitee chose at activation is too
 * weak.
 *
 * No password is chosen by the system and none is transmitted
 * (ADR-0069 §4, ADR-0081 S-07): the invitee picks it, so the rule
 * that guards it is enforced on their choice. The console answers
 * **422**.
 */
final class InvitationPasswordTooWeak extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'invitation-password-too-weak';

    public function __construct(public readonly int $minimumLength)
    {
        parent::__construct(sprintf(
            'The chosen password must be at least %d characters long.',
            $minimumLength,
        ));
    }

    public static function withMinimumLength(int $minimumLength): self
    {
        return new self($minimumLength);
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
