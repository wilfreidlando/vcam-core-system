<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use DateTimeImmutable;

/**
 * One member of an organization, as the console shows it.
 *
 * `role` is the raw canonical role string, not the `OrganizationRole`
 * enum (the enum lives in the Domain layer, which `Contracts/` may not
 * reference — G3c).
 */
final readonly class OrganizationMemberView
{
    public function __construct(
        public string $operatorId,
        public string $email,
        public string $name,
        public string $role,
        public DateTimeImmutable $joinedAt,
    ) {}
}
