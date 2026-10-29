<?php

declare(strict_types=1);

namespace Core\Parties\Contracts;

/**
 * Read-only counterpart of `PartyFinder` (ADR-0065): finds a party by its
 * external_ref without ever creating it — for a read ("this party's
 * wallets") or a transfer source, where a missing party means "nothing to
 * show / nothing to spend", never "create one".
 */
interface PartyLookup
{
    public function find(string $projectId, string $externalRef): ?PartyRef;

    /**
     * The ids of the parties of one project whose own reference contains a term: what a
     * listing's search box looks up, so an operator finds a party by the name they see.
     *
     * @return list<string>
     */
    public function idsMatchingRef(string $projectId, string $term, int $limit = 100): array;

    /**
     * The caller's own references for a set of party ids of one project: what a
     * listing shows instead of an internal identifier.
     *
     * @param list<string> $partyIds
     * @return array<string, string> party id → external reference
     */
    public function externalRefsOf(string $projectId, array $partyIds): array;
}
