<?php

declare(strict_types=1);

namespace Core\Parties\Contracts;

/**
 * The module's published surface for the lazy-creation flow (spec
 * fonctionnelle §4.3). Bound to `Core\Parties\Application\UseCase\FindOrCreateParty`.
 */
interface PartyFinder
{
    public function findOrCreate(FindOrCreatePartyCommand $command): PartyRef;
}
