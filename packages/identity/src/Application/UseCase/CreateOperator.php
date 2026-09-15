<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\CreatedOperator;
use Core\Identity\Application\Dto\CreateOperatorCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Support\Clock;
use Core\Support\Identifier;

/**
 * CLI-only bootstrap (mirrors `IssueApiKey`): the operator console does not
 * self-serve operator creation this round (ADR-0044) — an operator's
 * account is provisioned out of band, the same way a Project's first API
 * key is. MFA enrollment happens on first login, not here.
 */
final readonly class CreateOperator
{
    public function __construct(
        private OperatorRepository $operators,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(CreateOperatorCommand $command, string $actor, ?string $correlationId): CreatedOperator
    {
        $plainPassword = bin2hex(random_bytes(24));
        $now = $this->clock->now();

        $operator = new Operator(
            id: $this->identifier->generate(),
            email: $command->email,
            name: $command->name,
            passwordHash: Operator::hashPassword($plainPassword),
            role: $command->role,
            mfaSecret: null,
            mfaEnabled: false,
            revokedAt: null,
            createdAt: $now,
        );

        $this->operators->create($operator);

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'operator.created',
            resource: 'operator:'.$operator->id,
            before: null,
            after: ['email' => $operator->email, 'name' => $operator->name, 'role' => $operator->role->value],
            context: [],
            correlationId: $correlationId,
            occurredAt: $now,
        ));

        return new CreatedOperator($operator->id, $plainPassword);
    }
}
