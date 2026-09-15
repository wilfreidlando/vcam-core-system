<?php

declare(strict_types=1);

use Core\Ledger\Application\UseCase\PostLedgerTransaction;
use Core\Ledger\Contracts\Direction;
use Core\Ledger\Contracts\LedgerEntryInput;
use Core\Ledger\Contracts\PostLedgerTransactionCommand;
use Core\Ledger\Domain\Exception\UnbalancedTransaction;
use Core\Ledger\Tests\Unit\Fakes\InMemoryLedgerTransactionRepository;
use Core\Ledger\Tests\Unit\Fakes\SequentialIdentifier;

function postCommand(string $idempotencyKey = 'idem-1'): PostLedgerTransactionCommand
{
    return new PostLedgerTransactionCommand(
        'project-1',
        'wallet_credit',
        null,
        $idempotencyKey,
        [
            new LedgerEntryInput('clearing-account', Direction::Debit, 1_000, 'XAF'),
            new LedgerEntryInput('wallet-account', Direction::Credit, 1_000, 'XAF'),
        ],
    );
}

it('posts a new transaction and reports it as fresh', function (): void {
    $repository = new InMemoryLedgerTransactionRepository;
    $useCase = new PostLedgerTransaction($repository, new SequentialIdentifier);

    $ref = $useCase->post(postCommand());

    expect($ref->id)->toBe('id-1')
        ->and($ref->wasAlreadyPosted)->toBeFalse()
        ->and($repository->count())->toBe(1);
});

it('replays the same idempotency key as a no-op that reports wasAlreadyPosted', function (): void {
    $repository = new InMemoryLedgerTransactionRepository;
    $useCase = new PostLedgerTransaction($repository, new SequentialIdentifier);

    $first = $useCase->post(postCommand('idem-1'));
    $second = $useCase->post(postCommand('idem-1'));

    expect($second->id)->toBe($first->id)
        ->and($second->wasAlreadyPosted)->toBeTrue()
        ->and($repository->count())->toBe(1);
});

it('reports hasBeenPosted correctly before and after posting', function (): void {
    $repository = new InMemoryLedgerTransactionRepository;
    $useCase = new PostLedgerTransaction($repository, new SequentialIdentifier);

    expect($useCase->hasBeenPosted('project-1', 'idem-1'))->toBeFalse();

    $useCase->post(postCommand('idem-1'));

    expect($useCase->hasBeenPosted('project-1', 'idem-1'))->toBeTrue()
        ->and($useCase->hasBeenPosted('project-2', 'idem-1'))->toBeFalse();
});

it('rejects an unbalanced set of entries before persisting anything', function (): void {
    $repository = new InMemoryLedgerTransactionRepository;
    $useCase = new PostLedgerTransaction($repository, new SequentialIdentifier);

    $command = new PostLedgerTransactionCommand(
        'project-1',
        'wallet_credit',
        null,
        'idem-1',
        [
            new LedgerEntryInput('clearing-account', Direction::Debit, 1_000, 'XAF'),
            new LedgerEntryInput('wallet-account', Direction::Credit, 999, 'XAF'),
        ],
    );

    try {
        $useCase->post($command);
    } catch (UnbalancedTransaction) {
        // expected
    }

    expect($repository->count())->toBe(0);
});

it('scopes idempotency keys to the project', function (): void {
    $repository = new InMemoryLedgerTransactionRepository;
    $useCase = new PostLedgerTransaction($repository, new SequentialIdentifier);

    $projectOne = $useCase->post(new PostLedgerTransactionCommand(
        'project-1',
        'wallet_credit',
        null,
        'idem-shared',
        [
            new LedgerEntryInput('clearing-account', Direction::Debit, 1_000, 'XAF'),
            new LedgerEntryInput('wallet-account', Direction::Credit, 1_000, 'XAF'),
        ],
    ));

    $projectTwo = $useCase->post(new PostLedgerTransactionCommand(
        'project-2',
        'wallet_credit',
        null,
        'idem-shared',
        [
            new LedgerEntryInput('clearing-account', Direction::Debit, 1_000, 'XAF'),
            new LedgerEntryInput('wallet-account', Direction::Credit, 1_000, 'XAF'),
        ],
    ));

    expect($projectOne->wasAlreadyPosted)->toBeFalse()
        ->and($projectTwo->wasAlreadyPosted)->toBeFalse()
        ->and($projectOne->id)->not->toBe($projectTwo->id);
});
