<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `status` is a raw string, not an enum — the Domain model itself never
 * constrained it to a fixed set beyond `isActive()`'s `=== 'active'` check
 * (no dedicated status enum exists for Organization, unlike
 * `Core\Identity\OperatorRole`), so this stays consistent with that:
 * validation of the allowed set ('active'/'suspended') happens at the
 * HTTP boundary (the admin controller), not here.
 */
final readonly class UpdateOrganizationCommand
{
    public function __construct(
        public string $organizationId,
        public string $name,
        public string $status,
    ) {}
}
