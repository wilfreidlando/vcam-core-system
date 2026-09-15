<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Contracts\PartyFinder;
use Core\Parties\Contracts\PartyRef;

final class FakePartyFinder implements PartyFinder
{
    /** @var list<FindOrCreatePartyCommand> */
    public array $calls = [];

    private int $next = 1;

    /** @var array<string, PartyRef> */
    private array $byExternalRef = [];

    public function findOrCreate(FindOrCreatePartyCommand $command): PartyRef
    {
        $this->calls[] = $command;

        $key = "{$command->projectId}:{$command->externalRef}";

        return $this->byExternalRef[$key] ??= new PartyRef(
            'party-'.$this->next++,
            $command->projectId,
            $command->externalRef,
            $command->type,
            'none',
        );
    }
}
