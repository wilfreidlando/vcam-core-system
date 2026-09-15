<?php

declare(strict_types=1);

use Core\Identity\Application\Dto\LogoutOperatorCommand;
use Core\Identity\Application\UseCase\LogoutOperator;
use Core\Identity\Tests\Unit\Fakes\FakeOperatorTokenRevoker;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;

it('revokes a known token and records an audit entry', function (): void {
    $revoker = new FakeOperatorTokenRevoker;
    $audit = new RecordingAuditLogger;
    $revoker->register('a-real-token', 'op-1');

    $useCase = new LogoutOperator($revoker, $audit, new FixedClock);
    $useCase(new LogoutOperatorCommand('a-real-token'));

    expect($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('operator.logout')
        ->and($audit->entries[0]->actor)->toBe('operator:op-1');
});

it('is a silent no-op for an already-unknown token', function (): void {
    $revoker = new FakeOperatorTokenRevoker;
    $audit = new RecordingAuditLogger;

    $useCase = new LogoutOperator($revoker, $audit, new FixedClock);
    $useCase(new LogoutOperatorCommand('unknown-token'));

    expect($audit->entries)->toBeEmpty();
});
