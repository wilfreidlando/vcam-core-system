<?php

declare(strict_types=1);

namespace Core\Parties\Tests\Unit\Fakes;

use Core\Parties\Domain\Exception\PartyAlreadyExists;
use Core\Parties\Domain\Model\Party;
use Core\Parties\Domain\Port\PartyRepository;

final class InMemoryPartyRepository implements PartyRepository
{
    /**
     * When set, the next save() throws PartyAlreadyExists instead of
     * persisting — simulating a concurrent writer that landed its row first
     * — and that winning row becomes visible immediately afterwards, the
     * way a real unique-constraint race would leave it.
     */
    public ?Party $concurrentWinner = null;

    /** @var array<string, Party> */
    private array $parties = [];

    public function save(Party $party): void
    {
        if ($this->concurrentWinner !== null) {
            $winner = $this->concurrentWinner;
            $this->concurrentWinner = null;
            $this->parties[$winner->id] = $winner;

            throw PartyAlreadyExists::forExternalRef($party->projectId, $party->externalRef);
        }

        $this->parties[$party->id] = $party;
    }

    public function find(string $id): ?Party
    {
        return $this->parties[$id] ?? null;
    }

    public function findByExternalRef(string $projectId, string $externalRef, string $environment = 'live'): ?Party
    {
        foreach ($this->parties as $party) {
            if ($party->projectId === $projectId && $party->externalRef === $externalRef && $party->environment === $environment) {
                return $party;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->parties);
    }
}
