<?php

declare(strict_types=1);

use Core\Ledger\Application\UseCase\ResolveOrCreateAccount;
use Core\Ledger\Contracts\ResolveOrCreateAccountCommand;
use Core\Ledger\Domain\Model\AccountKind;
use Core\Ledger\Domain\Model\LedgerAccount;
use Core\Ledger\Tests\Unit\Fakes\InMemoryLedgerAccountRepository;
use Core\Ledger\Tests\Unit\Fakes\SequentialIdentifier;

it('creates a new account on first resolution', function (): void {
    $repository = new InMemoryLedgerAccountRepository;
    $useCase = new ResolveOrCreateAccount($repository, new SequentialIdentifier);

    $ref = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'XAF'));

    expect($ref->id)->toBe('id-1')
        ->and($ref->kind)->toBe('wallet')
        ->and($ref->reference)->toBe('wallet-1')
        ->and($ref->currency)->toBe('XAF')
        ->and($repository->count())->toBe(1);
});

it('returns the same account for the same composite key', function (): void {
    $repository = new InMemoryLedgerAccountRepository;
    $useCase = new ResolveOrCreateAccount($repository, new SequentialIdentifier);

    $first = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'XAF'));
    $second = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'XAF'));

    expect($second->id)->toBe($first->id)
        ->and($repository->count())->toBe(1);
});

it('treats a project-level account (empty reference) as distinct from a referenced one', function (): void {
    $repository = new InMemoryLedgerAccountRepository;
    $useCase = new ResolveOrCreateAccount($repository, new SequentialIdentifier);

    $clearing = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'clearing', '', 'XAF'));
    $wallet = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'XAF'));

    expect($clearing->id)->not->toBe($wallet->id)
        ->and($clearing->reference)->toBe('')
        ->and($repository->count())->toBe(2);
});

it('distinguishes the same kind and reference by currency', function (): void {
    $repository = new InMemoryLedgerAccountRepository;
    $useCase = new ResolveOrCreateAccount($repository, new SequentialIdentifier);

    $xaf = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'XAF'));
    $usd = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'USD'));

    expect($xaf->id)->not->toBe($usd->id)
        ->and($repository->count())->toBe(2);
});

it('rejects a malformed kind before touching the repository', function (): void {
    $repository = new InMemoryLedgerAccountRepository;
    $useCase = new ResolveOrCreateAccount($repository, new SequentialIdentifier);

    try {
        $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'Not Valid', '', 'XAF'));
    } catch (InvalidArgumentException) {
        // expected
    }

    expect($repository->count())->toBe(0);
});

it('recovers the winning account when it loses a concurrent creation race', function (): void {
    $repository = new InMemoryLedgerAccountRepository;
    $useCase = new ResolveOrCreateAccount($repository, new SequentialIdentifier);

    $repository->concurrentWinner = new LedgerAccount('winner-id', 'project-1', AccountKind::fromString('wallet'), 'wallet-1', 'XAF');

    $ref = $useCase->resolveOrCreate(new ResolveOrCreateAccountCommand('project-1', 'wallet', 'wallet-1', 'XAF'));

    expect($ref->id)->toBe('winner-id')
        ->and($repository->count())->toBe(1);
});
