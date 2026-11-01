<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `status` is a raw string, not an enum — same reasoning as
 * `UpdateOrganizationCommand`: the Domain model never constrained it
 * beyond `isActive()`'s `=== 'active'` check. Deliberately does not carry
 * `organizationId` — a Project never moves between organizations, only
 * its name and status change.
 */
final readonly class UpdateProjectCommand
{
    public function __construct(
        public string $projectId,
        public string $name,
        public string $status,
        // ADR-0069 §3 — mandatory when the status changes, audited with
        // the transition.
        public ?string $reason = null,
    ) {}
}
