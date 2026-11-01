<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

/**
 * ADR-0069 §2 — the membership named by the route does not exist in
 * that organization.
 *
 * A membership of another organization is **not** a different answer:
 * the console answers **404** either way, so a caller cannot probe the
 * members of an organization it does not see (S-04).
 */
final class OrganizationMemberNotFound extends RuntimeException implements OrganizationRefusal
{
    public const string CODE = 'organization-member-not-found';

    public function __construct(
        public readonly string $organizationId,
        public readonly string $operatorId,
    ) {
        parent::__construct(sprintf(
            'No member "%s" in organization %s.',
            $operatorId,
            $organizationId,
        ));
    }

    public static function forMember(string $organizationId, string $operatorId): self
    {
        return new self($organizationId, $operatorId);
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
