<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\UpdateOperator as UpdateOperatorPort;
use Core\Identity\Contracts\UpdateOperatorCommand;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Support\Clock;

/**
 * Profile-only: email/name/role. Never touches credentials or MFA state —
 * those remain separate actions with their own use cases.
 */
final readonly class UpdateOperator implements UpdateOperatorPort
{
    public function __construct(
        private OperatorRepository $operators,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function update(UpdateOperatorCommand $command, string $actor, ?string $correlationId): void
    {
        $operator = $this->operators->find($command->operatorId)
            ?? throw OperatorNotFound::withId($command->operatorId);

        $role = OperatorRole::tryFrom($command->role)
            ?? throw InvalidOperatorRole::withValue($command->role);

        $before = ['email' => $operator->email, 'name' => $operator->name, 'role' => $operator->role->value];

        $operator->updateProfile($command->email, $command->name, $role);

        $this->operators->save($operator);

        $after = ['email' => $operator->email, 'name' => $operator->name, 'role' => $operator->role->value];

        if ($before === $after) {
            // No actual change — don't write a misleading audit entry.
            return;
        }

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'operator.updated',
            resource: 'operator:'.$operator->id,
            before: array_diff_assoc($before, $after),
            after: array_diff_assoc($after, $before),
            context: [],
            correlationId: $correlationId,
            occurredAt: $this->clock->now(),
        ));
    }
}
