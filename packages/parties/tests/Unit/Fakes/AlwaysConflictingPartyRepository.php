<?php

declare(strict_types=1);

namespace Core\Parties\Tests\Unit\Fakes;

use Core\Parties\Domain\Exception\PartyAlreadyExists;
use Core\Parties\Domain\Model\Party;
use Core\Parties\Domain\Port\PartyRepository;

/**
 * Every save() throws PartyAlreadyExists and no row is ever visible
 * afterwards — models a broken invariant (the unique constraint fired with
 * no real winner on disk), used to prove the caller fails loudly rather
 * than silently swallowing an inconsistency.
 */
final class AlwaysConflictingPartyRepository implements PartyRepository
{
    public function save(Party $party): void
    {
        throw PartyAlreadyExists::forExternalRef($party->projectId, $party->externalRef);
    }

    public function find(string $id): ?Party
    {
        return null;
    }

    public function findByExternalRef(string $projectId, string $externalRef, string $environment = 'live'): ?Party
    {
        return null;
    }
}
