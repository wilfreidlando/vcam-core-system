<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\CreatedOperatorView;
use Core\Identity\Contracts\CreateOperator as CreateOperatorPort;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Support\Clock;
use Core\Support\Identifier;

/**
 * Reachable from both the CLI (`identity:create-operator`) and the admin
 * console's self-service endpoint (`POST /v1/admin/operators`, ADR-0050) —
 * a single place where an operator account is provisioned, whichever
 * caller triggers it. MFA enrollment always happens on first login, never
 * here.
 */
final readonly class CreateOperator implements CreateOperatorPort
{
    public function __construct(
        private OperatorRepository $operators,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function create(CreateOperatorCommand $command, string $actor, ?string $correlationId): CreatedOperatorView
    {
        $role = OperatorRole::tryFrom($command->role)
            ?? throw InvalidOperatorRole::withValue($command->role);

        $plainPassword = bin2hex(random_bytes(24));
        $now = $this->clock->now();

        $operator = new Operator(
            id: $this->identifier->generate(),
            email: $command->email,
            name: $command->name,
            passwordHash: Operator::hashPassword($plainPassword),
            role: $role,
            mfaSecret: null,
            mfaEnabled: false,
            mfaLastVerifiedTimestep: null,
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

        return new CreatedOperatorView($operator->id, $plainPassword);
    }
}
