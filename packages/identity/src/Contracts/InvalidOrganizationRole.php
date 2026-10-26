<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * The requested organization role is not one of the five roles
 * ADR-0069 §1 fixes (`org_owner`, `org_admin`, `org_finance`,
 * `org_support`, `org_viewer`).
 *
 * The console answers **422** — the request is malformed, not
 * forbidden.
 */
final class InvalidOrganizationRole extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'invalid-organization-role';

    public function __construct(public readonly string $role)
    {
        parent::__construct(sprintf(
            '"%s" is not a valid organization role. Must be one of: %s.',
            $role,
            implode(', ', ['org_owner', 'org_admin', 'org_finance', 'org_support', 'org_viewer']),
        ));
    }

    public static function withValue(string $role): self
    {
        return new self($role);
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
