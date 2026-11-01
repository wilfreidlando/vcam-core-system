<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §2 — the organization does not exist **or** is outside the
 * caller's scope. Both answers are the same, on purpose: confirming
 * the existence of a foreign organization would leak another tenant's
 * data (S-04 — "404, never 403").
 *
 * The console answers **404**.
 */
final class OrganizationNotVisible extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'organization-not-found';

    public function __construct(public readonly string $organizationId)
    {
        parent::__construct(sprintf('Organization %s does not exist.', $organizationId));
    }

    public static function withId(string $organizationId): self
    {
        return new self($organizationId);
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
