<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §4 — no invitation matches the presented token.
 *
 * The refusal deliberately says nothing about *why*: an unknown token, a
 * revoked one and a forged one are indistinguishable from the outside.
 * The console answers **404**.
 */
final class OrganizationInvitationNotFound extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'organization-invitation-not-found';

    public function __construct()
    {
        parent::__construct('No invitation matches this activation token.');
    }

    public static function forToken(): self
    {
        return new self;
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
