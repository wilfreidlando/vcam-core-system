<?php

declare(strict_types=1);

use Core\Identity\Domain\Model\Operator;
use Core\Identity\OperatorRole;

function makeOperator(): Operator
{
    return new Operator(
        id: 'op-1',
        email: 'ops@example.com',
        name: 'Ops',
        passwordHash: Operator::hashPassword('correct-horse'),
        role: OperatorRole::SupportOperator,
        mfaSecret: null,
        mfaEnabled: false,
        revokedAt: null,
        createdAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    );
}

it('verifies the correct password and rejects a wrong one', function (): void {
    $operator = makeOperator();

    expect($operator->verifyPassword('correct-horse'))->toBeTrue()
        ->and($operator->verifyPassword('wrong-password'))->toBeFalse();
});

it('is not mfa-enabled until enrollment is confirmed', function (): void {
    $operator = makeOperator();
    expect($operator->mfaEnabled())->toBeFalse();

    $operator->enrollMfa('SECRET123');
    expect($operator->mfaEnabled())->toBeFalse()
        ->and($operator->mfaSecret())->toBe('SECRET123');

    $operator->confirmMfa();
    expect($operator->mfaEnabled())->toBeTrue();
});

it('is revoked exactly once, idempotently', function (): void {
    $operator = makeOperator();
    expect($operator->isRevoked())->toBeFalse();

    $at = new DateTimeImmutable('2026-09-15T12:00:00Z');
    $operator->revoke($at);
    $operator->revoke(new DateTimeImmutable('2026-09-16T00:00:00Z'));

    expect($operator->isRevoked())->toBeTrue()
        ->and($operator->revokedAt())->toEqual($at);
});
