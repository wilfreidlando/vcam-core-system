<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Support\PlatformAdministratorPolicy;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\UpdateOperator as UpdateOperatorPort;
use Core\Identity\Contracts\UpdateOperatorCommand;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Support\Clock;
use Core\Support\UnitOfWork;

/**
 * Profile-only: email/name/role. Never touches credentials or MFA state —
 * those remain separate actions with their own use cases.
 *
 * Concurrency: the whole mutation runs in one transaction, so the row
 * lock taken by the last-administrator guard is held until the role
 * change is committed. Two concurrent demotions cannot both pass the
 * guard and leave the console without an administrator.
 */
final readonly class UpdateOperator implements UpdateOperatorPort
{
    public function __construct(
        private OperatorRepository $operators,
        private AuditLogger $audit,
        private Clock $clock,
        private PlatformAdministratorPolicy $policy,
        private UnitOfWork $unitOfWork,
    ) {}

    public function update(UpdateOperatorCommand $command, string $actor, ?string $correlationId): void
    {
        $this->unitOfWork->transactional(function () use ($command, $actor, $correlationId): void {
            $operator = $this->operators->find($command->operatorId)
                ?? throw OperatorNotFound::withId($command->operatorId);

            // ADR-0069 §1: an empty role means "no platform role".
            $role = trim($command->role) === ''
                ? null
                : (OperatorRole::tryFrom($command->role) ?? throw InvalidOperatorRole::withValue($command->role));

            // Audit 2026-10-29 #6 / G-5 — same guard as the revocation: the last
            // platform administrator is never demoted, nobody changes their own
            // role. It runs inside the transaction, so its row lock is held
            // until the change is committed.
            $this->policy->assertCanChangeRole($operator, $role, $actor);

            $before = ['email' => $operator->email, 'name' => $operator->name, 'role' => $operator->role?->value];

            // ADR-0069 §1 — one console account per mailbox: the address is
            // normalized and an address already taken by ANOTHER account is
            // refused. Keeping its own address is not a conflict.
            $email = strtolower(trim($command->email));
            $existing = $this->operators->findByEmail($email);

            if ($existing !== null && $existing->id !== $operator->id) {
                throw OperatorEmailAlreadyTaken::forEmail($email);
            }

            $operator->updateProfile($email, $command->name, $role);

            $this->operators->save($operator);

            $after = ['email' => $operator->email, 'name' => $operator->name, 'role' => $operator->role?->value];

            if ($before === $after) {
                // No actual change — don't write a misleading audit entry.
                return;
            }

            // ADR-0083 §4 — the journal records WHICH fields changed, never
            // their values: it is append-only and tamper-evident, so a
            // personal value written there could never be erased. The role
            // is not personal data and stays readable.
            $changed = [];
            foreach (['email', 'name', 'role'] as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changed[] = $field;
                }
            }

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'operator.updated',
                resource: 'operator:'.$operator->id,
                before: null,
                after: [
                    'operator_id' => $operator->id,
                    'changed' => $changed,
                    'role' => $after['role'],
                ],
                context: [],
                correlationId: $correlationId,
                occurredAt: $this->clock->now(),
            ));
        });
    }
}
