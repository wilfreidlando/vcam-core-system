<?php

declare(strict_types=1);

namespace Core\Parties\Application\UseCase;

use Core\Parties\Contracts\PartyLookup;
use Core\Parties\Contracts\PartyRef;
use Core\Parties\Domain\Port\PartyRepository;

final readonly class FindPartyByExternalRef implements PartyLookup
{
    public function __construct(
        private PartyRepository $parties,
    ) {}

    public function find(string $projectId, string $environment, string $externalRef): ?PartyRef
    {
        $party = $this->parties->findByExternalRef($projectId, $externalRef, $environment);

        return $party === null
            ? null
            : new PartyRef($party->id, $party->projectId, $party->externalRef, $party->type(), $party->kycStatus(), $party->environment);
    }
}
