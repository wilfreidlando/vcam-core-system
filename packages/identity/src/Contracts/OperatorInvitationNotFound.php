<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0081 S-07 — no invitation matches the presented token.
 *
 * The refusal deliberately says nothing about *why*: an unknown token, a
 * revoked one and a forged one are indistinguishable from the outside.
 * The console answers **404**.
 *
 * It implements `OrganizationRefusal` — the interface `identity`
 * publishes for the refusals its own console translates (the name
 * predates this refusal; it is the module's single refusal contract).
 */
final class OperatorInvitationNotFound extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'operator-invitation-not-found';

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
