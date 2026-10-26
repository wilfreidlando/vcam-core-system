<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 — "personne ne peut attribuer un rôle de plateforme par ce
 * chemin".
 *
 * A platform role (`platform_admin`, `finance_operator`, …) is granted
 * by the platform itself, never through an organization membership.
 * The console answers **403**.
 */
final class PlatformRoleAssignmentForbidden extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'platform-role-forbidden';

    public function __construct(
        public readonly string $organizationId,
        public readonly string $role,
    ) {
        parent::__construct(sprintf(
            'The platform role "%s" cannot be granted through the membership of organization %s: platform roles are granted by the platform.',
            $role,
            $organizationId,
        ));
    }

    public static function forRole(string $organizationId, string $role): self
    {
        return new self($organizationId, $role);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
