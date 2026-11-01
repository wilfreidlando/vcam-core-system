<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * The active members of one organization.
 *
 * No pagination: a tenant has members by the dozen, not by the
 * thousand, and the whole point of the listing is to be read at a
 * glance.
 */
final readonly class OrganizationMembersView
{
    /**
     * @param list<OrganizationMemberView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {}
}
