<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\Organization;

interface OrganizationRepository
{
    public function save(Organization $organization): void;

    public function find(string $id): ?Organization;

    /**
     * Newest first — the admin console's own listing (review finding,
     * Lot 6 follow-up: the console used to fake an organization list by
     * de-duplicating one page of *projects*, which reported a wrong
     * total and dropped organizations that fell outside that page).
     * `$search`, when given, matches a case-insensitive substring of
     * `name` — the only identifying field an operator has to go on when
     * hunting for one organization among many.
     *
     * @return array{items: list<Organization>, total: int}
     */
    public function findAll(int $page, int $perPage, ?string $search = null): array;
}
