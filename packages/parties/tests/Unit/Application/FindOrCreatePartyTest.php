<?php

declare(strict_types=1);

use Core\Parties\Application\UseCase\FindOrCreateParty;
use Core\Parties\Contracts\FindOrCreatePartyCommand;
use Core\Parties\Domain\Model\Party;
use Core\Parties\Tests\Unit\Fakes\AlwaysConflictingPartyRepository;
use Core\Parties\Tests\Unit\Fakes\InMemoryPartyRepository;
use Core\Parties\Tests\Unit\Fakes\SequentialIdentifier;

it('creates a new party on first call', function (): void {
    $repository = new InMemoryPartyRepository;
    $useCase = new FindOrCreateParty($repository, new SequentialIdentifier);

    $ref = $useCase->findOrCreate(new FindOrCreatePartyCommand('project-1', 'ext-1', 'provider'));

    expect($ref->id)->toBe('id-1')
        ->and($ref->projectId)->toBe('project-1')
        ->and($ref->externalRef)->toBe('ext-1')
        ->and($ref->type)->toBe('provider')
        ->and($ref->kycStatus)->toBe('none')
        ->and($repository->count())->toBe(1);
});

it('returns the same party on a second call for the same external_ref', function (): void {
    $repository = new InMemoryPartyRepository;
    $useCase = new FindOrCreateParty($repository, new SequentialIdentifier);

    $first = $useCase->findOrCreate(new FindOrCreatePartyCommand('project-1', 'ext-1', 'provider'));
    $second = $useCase->findOrCreate(new FindOrCreatePartyCommand('project-1', 'ext-1', 'provider'));

    expect($second->id)->toBe($first->id)
        ->and($repository->count())->toBe(1);
});

it('treats the same external_ref under a different project as a different party', function (): void {
    $repository = new InMemoryPartyRepository;
    $useCase = new FindOrCreateParty($repository, new SequentialIdentifier);

    $first = $useCase->findOrCreate(new FindOrCreatePartyCommand('project-1', 'ext-1', 'provider'));
    $second = $useCase->findOrCreate(new FindOrCreatePartyCommand('project-2', 'ext-1', 'provider'));

    expect($second->id)->not->toBe($first->id)
        ->and($repository->count())->toBe(2);
});

it('recovers the winning party when it loses a concurrent creation race', function (): void {
    $repository = new InMemoryPartyRepository;
    $useCase = new FindOrCreateParty($repository, new SequentialIdentifier);

    // Another process's insert lands between our findByExternalRef() miss
    // and our own save() — the unique constraint rejects our insert.
    $repository->concurrentWinner = Party::create('winner-id', 'project-1', 'ext-1', 'provider');

    $ref = $useCase->findOrCreate(new FindOrCreatePartyCommand('project-1', 'ext-1', 'provider'));

    expect($ref->id)->toBe('winner-id')
        ->and($repository->count())->toBe(1);
});

it('surfaces a lost race with no winner on disk as a logic error, never a silent duplicate', function (): void {
    // This should be unreachable in production: the unique constraint only
    // rejects an insert when a row genuinely exists. Exercising it is what
    // proves the use case fails loudly rather than papering over an
    // inconsistency, should that invariant ever be violated.
    $repository = new AlwaysConflictingPartyRepository;
    $useCase = new FindOrCreateParty($repository, new SequentialIdentifier);

    $useCase->findOrCreate(new FindOrCreatePartyCommand('project-1', 'ext-1', 'provider'));
})->throws(LogicException::class, 'Party creation conflicted but no party was found afterwards.');
