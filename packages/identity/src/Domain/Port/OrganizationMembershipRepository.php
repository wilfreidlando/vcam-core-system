<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\OrganizationMembership;

interface OrganizationMembershipRepository
{
    public function save(OrganizationMembership $membership): void;

    public function find(string $id): ?OrganizationMembership;

    /**
     * Every active membership of an account, whatever the organization.
     *
     * @return list<OrganizationMembership>
     */
    public function findActiveByOperator(string $operatorId): array;

    /**
     * The active membership of an account in one organization, if any.
     */
    public function findActive(string $organizationId, string $operatorId): ?OrganizationMembership;

    /**
     * Every active membership of an organization.
     *
     * @return list<OrganizationMembership>
     */
    public function findActiveByOrganization(string $organizationId): array;

    /**
     * How many accounts hold the `org_owner` role in this organization and
     * are still active — the last owner cannot be revoked nor demoted.
     *
     * The active owner rows are **locked** until the surrounding
     * transaction ends (`SELECT … FOR UPDATE`, in a stable order so two
     * callers never deadlock): two owners revoking each other at the same
     * time are serialized, and the second one counts what the first left
     * behind. Must be called inside a transaction, before the membership
     * being changed is read.
     */
    public function lockActiveOwners(string $organizationId): int;

    /**
     * The ids of the accounts that are **active members** of at least one
     * of the given organizations — the console's operator listing scope
     * (ADR-0069 §2).
     *
     * The membership table is the source of truth for "who belongs to
     * which organization": a revoked membership grants nothing, so it is
     * excluded here rather than filtered later.
     *
     * @param list<string> $organizationIds
     * @return list<string> the operator ids, without duplicates
     */
    public function findActiveOperatorIdsByOrganizations(array $organizationIds): array;
}
