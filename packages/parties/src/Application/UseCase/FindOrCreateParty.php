<?php

declare(strict_types=1);

namespace Core\Parties\Application\UseCase;

use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Parties\Contracts\PartyRef;
use Core\Parties\Domain\Exception\PartyAlreadyExists;
use Core\Parties\Domain\Model\Party;
use Core\Parties\Domain\Port\PartyRepository;
use Core\Support\Identifier;
use LogicException;

/**
 * The lazy-creation primitive (spec fonctionnelle §4.3): the Core only ever
 * materialises a Party at the first real need. Idempotent by construction —
 * calling this twice with the same (projectId, externalRef) returns the same
 * Party both times, never a duplicate.
 */
final readonly class FindOrCreateParty implements PartyFinder
{
    public function __construct(
        private PartyRepository $parties,
        private Identifier $identifier,
    ) {}

    public function findOrCreate(FindOrCreatePartyCommand $command): PartyRef
    {
        $existing = $this->parties->findByExternalRef($command->projectId, $command->externalRef, $command->environment);

        if ($existing !== null) {
            return $this->toRef($existing);
        }

        $party = Party::create(
            $this->identifier->generate(),
            $command->projectId,
            $command->externalRef,
            $command->type,
            $command->metadata,
            $command->environment,
        );

        try {
            $this->parties->save($party);
        } catch (PartyAlreadyExists) {
            // Lost a race: another concurrent call created it first.
            $winner = $this->parties->findByExternalRef($command->projectId, $command->externalRef, $command->environment)
                ?? throw new LogicException('Party creation conflicted but no party was found afterwards.');

            return $this->toRef($winner);
        }

        return $this->toRef($party);
    }

    private function toRef(Party $party): PartyRef
    {
        return new PartyRef($party->id, $party->projectId, $party->externalRef, $party->type(), $party->kycStatus(), $party->environment);
    }
}
