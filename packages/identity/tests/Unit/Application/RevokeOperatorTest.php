<?php

declare(strict_types=1);

use Core\Identity\Application\Support\PlatformAdministratorPolicy;
use Core\Identity\Application\UseCase\RevokeOperator;
use Core\Identity\Contracts\RevokeOperatorCommand;
use Core\Identity\Domain\Exception\CannotRevokeOwnAccount;
use Core\Identity\Domain\Exception\LastPlatformAdmin;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;

function revokeTestOperator(InMemoryOperatorRepository $operators, string $id, OperatorRole $role): Operator
{
    $operator = new Operator(
        id: $id,
        email: $id.'@example.com',
        name: $id,
        passwordHash: Operator::hashPassword('correct-horse'),
        role: $role,
        mfaSecret: null,
        mfaEnabled: false,
        mfaLastVerifiedTimestep: null,
        revokedAt: null,
        createdAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    );
    $operators->create($operator);

    return $operator;
}

function revokeOperatorAs(InMemoryOperatorRepository $operators, string $operatorId, string $actor): void
{
    (new RevokeOperator($operators, new RecordingAuditLogger, new FixedClock, new PlatformAdministratorPolicy($operators)))
        ->revoke(new RevokeOperatorCommand($operatorId), $actor, null);
}

it('refuses to revoke the last active platform administrator', function (): void {
    $operators = new InMemoryOperatorRepository;
    $admin = revokeTestOperator($operators, 'admin-1', OperatorRole::PlatformAdmin);

    expect(fn () => revokeOperatorAs($operators, 'admin-1', 'operator:someone'))->toThrow(LastPlatformAdmin::class)
        ->and($admin->isRevoked())->toBeFalse();
});

it('revokes an administrator when another active one remains', function (): void {
    $operators = new InMemoryOperatorRepository;
    $admin = revokeTestOperator($operators, 'admin-1', OperatorRole::PlatformAdmin);
    revokeTestOperator($operators, 'admin-2', OperatorRole::PlatformAdmin);

    revokeOperatorAs($operators, 'admin-1', 'operator:admin-2');

    expect($admin->isRevoked())->toBeTrue();
});

it('refuses a self-revocation', function (): void {
    $operators = new InMemoryOperatorRepository;
    revokeTestOperator($operators, 'admin-1', OperatorRole::PlatformAdmin);
    revokeTestOperator($operators, 'admin-2', OperatorRole::PlatformAdmin);

    revokeOperatorAs($operators, 'admin-1', 'operator:admin-1');
})->throws(CannotRevokeOwnAccount::class);

it('revokes a non-administrator without any administrator-count check', function (): void {
    $operators = new InMemoryOperatorRepository;
    $support = revokeTestOperator($operators, 'op-1', OperatorRole::SupportOperator);

    revokeOperatorAs($operators, 'op-1', 'operator:admin-1');

    expect($support->isRevoked())->toBeTrue();
});
