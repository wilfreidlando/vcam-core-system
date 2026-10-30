<?php

declare(strict_types=1);

use Core\Identity\Application\Support\PlatformAdministratorPolicy;
use Core\Identity\Application\UseCase\UpdateOperator;
use Core\Identity\Contracts\CannotChangeOwnPlatformRole;
use Core\Identity\Contracts\LastPlatformAdminDemotion;
use Core\Identity\Contracts\UpdateOperatorCommand;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
use Core\Identity\Tests\Unit\Fakes\PassthroughUnitOfWork;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;

function seedOperator(InMemoryOperatorRepository $operators): Operator
{
    $operator = new Operator(
        id: 'op-1',
        email: 'ops@example.com',
        name: 'Ops',
        passwordHash: Operator::hashPassword('correct-horse'),
        role: OperatorRole::SupportOperator,
        mfaSecret: null,
        mfaEnabled: false,
        mfaLastVerifiedTimestep: null,
        revokedAt: null,
        createdAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    );

    $operators->create($operator);

    return $operator;
}

it('updates an operator profile, persists it, and audits only the changed fields', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operator = seedOperator($operators);

    $audit = new RecordingAuditLogger;
    $useCase = new UpdateOperator($operators, $audit, new FixedClock, new PlatformAdministratorPolicy($operators), new PassthroughUnitOfWork);

    $useCase->update(
        new UpdateOperatorCommand(
            operatorId: 'op-1',
            email: 'new-ops@example.com',
            name: 'Ops',
            role: OperatorRole::FinanceOperator->value,
        ),
        actor: 'operator:admin-1',
        correlationId: 'corr-1',
    );

    expect($operator->email)->toBe('new-ops@example.com')
        ->and($operator->name)->toBe('Ops')
        ->and($operator->role)->toBe(OperatorRole::FinanceOperator)
        ->and($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('operator.updated')
        ->and($audit->entries[0]->resource)->toBe('operator:op-1')
        // ADR-0083 §4 — the journal says WHICH fields changed, never
        // their values: it is append-only, a personal value could never
        // be erased from it.
        ->and($audit->entries[0]->before)->toBeNull()
        ->and($audit->entries[0]->after)->toBe([
            'operator_id' => 'op-1',
            'changed' => ['email', 'role'],
            'role' => 'finance_operator',
        ]);
});

it('throws when the operator does not exist', function (): void {
    $useCase = new UpdateOperator($missing = new InMemoryOperatorRepository, new RecordingAuditLogger, new FixedClock, new PlatformAdministratorPolicy($missing), new PassthroughUnitOfWork);

    $useCase->update(
        new UpdateOperatorCommand(operatorId: 'missing', email: 'x@example.com', name: 'X', role: 'finance_operator'),
        actor: 'operator:admin-1',
        correlationId: null,
    );
})->throws(OperatorNotFound::class);

it('throws when the role string is invalid', function (): void {
    $operators = new InMemoryOperatorRepository;
    seedOperator($operators);

    $useCase = new UpdateOperator($operators, new RecordingAuditLogger, new FixedClock, new PlatformAdministratorPolicy($operators), new PassthroughUnitOfWork);

    $useCase->update(
        new UpdateOperatorCommand(operatorId: 'op-1', email: 'ops@example.com', name: 'Ops', role: 'not_a_role'),
        actor: 'operator:admin-1',
        correlationId: null,
    );
})->throws(InvalidOperatorRole::class);

function seedAdmin(InMemoryOperatorRepository $operators, string $id): Operator
{
    $admin = new Operator(
        id: $id,
        email: $id.'@example.com',
        name: 'Admin '.$id,
        passwordHash: Operator::hashPassword('correct-horse'),
        role: OperatorRole::PlatformAdmin,
        mfaSecret: null,
        mfaEnabled: false,
        mfaLastVerifiedTimestep: null,
        revokedAt: null,
        createdAt: new DateTimeImmutable('2026-09-15T10:00:00Z'),
    );
    $operators->create($admin);

    return $admin;
}

function demote(InMemoryOperatorRepository $operators, string $operatorId, string $actor, string $role = 'support_operator'): void
{
    (new UpdateOperator($operators, new RecordingAuditLogger, new FixedClock, new PlatformAdministratorPolicy($operators), new PassthroughUnitOfWork))->update(
        new UpdateOperatorCommand(operatorId: $operatorId, email: $operatorId.'@example.com', name: 'Admin '.$operatorId, role: $role),
        actor: $actor,
        correlationId: null,
    );
}

it('refuses to demote the last active platform administrator (audit 2026-10-29 #6)', function (): void {
    $operators = new InMemoryOperatorRepository;
    $admin = seedAdmin($operators, 'admin-1');
    seedOperator($operators);

    expect(fn () => demote($operators, 'admin-1', 'operator:op-1'))->toThrow(LastPlatformAdminDemotion::class)
        ->and($admin->role)->toBe(OperatorRole::PlatformAdmin);
});

it('refuses to remove the platform role of the last administrator too', function (): void {
    $operators = new InMemoryOperatorRepository;
    seedAdmin($operators, 'admin-1');

    expect(fn () => demote($operators, 'admin-1', 'operator:op-1', role: ''))->toThrow(LastPlatformAdminDemotion::class);
});

it('demotes an administrator when another active one remains', function (): void {
    $operators = new InMemoryOperatorRepository;
    $admin = seedAdmin($operators, 'admin-1');
    seedAdmin($operators, 'admin-2');

    demote($operators, 'admin-1', 'operator:admin-2');

    expect($admin->role)->toBe(OperatorRole::SupportOperator);
});

it('does not count a revoked administrator as the second one', function (): void {
    $operators = new InMemoryOperatorRepository;
    seedAdmin($operators, 'admin-1');
    seedAdmin($operators, 'admin-2')->revoke(new DateTimeImmutable('2026-09-16T10:00:00Z'));

    expect(fn () => demote($operators, 'admin-1', 'operator:op-1'))->toThrow(LastPlatformAdminDemotion::class);
});

it('refuses a self-demotion even when another administrator exists', function (): void {
    $operators = new InMemoryOperatorRepository;
    $admin = seedAdmin($operators, 'admin-1');
    seedAdmin($operators, 'admin-2');

    expect(fn () => demote($operators, 'admin-1', 'operator:admin-1'))->toThrow(CannotChangeOwnPlatformRole::class)
        ->and($admin->role)->toBe(OperatorRole::PlatformAdmin);
});

it('lets an administrator edit their own profile without touching their role', function (): void {
    $operators = new InMemoryOperatorRepository;
    $admin = seedAdmin($operators, 'admin-1');

    demote($operators, 'admin-1', 'operator:admin-1', role: OperatorRole::PlatformAdmin->value);

    expect($admin->role)->toBe(OperatorRole::PlatformAdmin);
});
