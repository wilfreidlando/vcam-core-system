<?php

declare(strict_types=1);

namespace Core\Parties\Domain\Port;

use Core\Parties\Domain\Model\Party;

interface PartyRepository
{
    public function save(Party $party): void;

    public function find(string $id): ?Party;

    public function findByExternalRef(string $projectId, string $externalRef): ?Party;

    /**
     * The caller's own references of the parties with these ids, within one project.
     *
     * @param list<string> $partyIds
     * @return array<string, string> party id → external reference
     */
    public function externalRefsOf(string $projectId, array $partyIds): array;
}
