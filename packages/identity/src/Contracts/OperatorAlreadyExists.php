<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §4 — the invited email address carries a console account the
 * invitation cannot be accepted with: a **revoked** one. An active
 * existing account joins the organization instead (it proves it holds
 * the account by presenting its current password — see
 * `InvitationAccountPasswordInvalid`); a revoked account is not brought
 * back to life by an organization invitation: only the platform
 * reinstates a revoked console account.
 *
 * The console answers **409**.
 */
final class OperatorAlreadyExists extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'operator-already-exists';

    public function __construct(public readonly string $email)
    {
        parent::__construct(sprintf(
            'The console account of "%s" has been revoked: this invitation cannot be accepted with it.',
            $email,
        ));
    }

    public static function revoked(string $email): self
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
