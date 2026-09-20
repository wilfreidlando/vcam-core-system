<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\UpdateOperator;
use Core\Identity\Contracts\UpdateOperatorCommand;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
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
    $useCase = new UpdateOperator($operators, $audit, new FixedClock);

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
        ->and($audit->entries[0]->before)->toBe(['email' => 'ops@example.com', 'role' => 'support_operator'])
        ->and($audit->entries[0]->after)->toBe(['email' => 'new-ops@example.com', 'role' => 'finance_operator']);
});

it('throws when the operator does not exist', function (): void {
    $useCase = new UpdateOperator(new InMemoryOperatorRepository, new RecordingAuditLogger, new FixedClock);

    $useCase->update(
        new UpdateOperatorCommand(operatorId: 'missing', email: 'x@example.com', name: 'X', role: 'finance_operator'),
        actor: 'operator:admin-1',
        correlationId: null,
    );
})->throws(OperatorNotFound::class);

it('throws when the role string is invalid', function (): void {
    $operators = new InMemoryOperatorRepository;
    seedOperator($operators);

    $useCase = new UpdateOperator($operators, new RecordingAuditLogger, new FixedClock);

    $useCase->update(
        new UpdateOperatorCommand(operatorId: 'op-1', email: 'ops@example.com', name: 'Ops', role: 'not_a_role'),
        actor: 'operator:admin-1',
        correlationId: null,
    );
})->throws(InvalidOperatorRole::class);
