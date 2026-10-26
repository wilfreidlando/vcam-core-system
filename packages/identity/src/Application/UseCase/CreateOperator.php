<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\CreatedOperatorView;
use Core\Identity\Contracts\CreateOperator as CreateOperatorPort;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
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
        // ADR-0069 §1: an empty role means "no platform role" — the
        // account then lives in its organizations' memberships only.
        $role = trim($command->role) === ''
            ? null
            : (OperatorRole::tryFrom($command->role) ?? throw InvalidOperatorRole::withValue($command->role));

        $plainPassword = bin2hex(random_bytes(24));
        $now = $this->clock->now();

        // ADR-0069 §1 — one console account per mailbox: the address is
        // normalized (a mailbox does not care about the case of its local
        // part) and an address already taken is refused, rather than
        // reaching the unique index and surfacing as a database error.
        $email = strtolower(trim($command->email));

        if ($this->operators->findByEmail($email) !== null) {
            throw OperatorEmailAlreadyTaken::forEmail($email);
        }

        $operator = new Operator(
            id: $this->identifier->generate(),
            email: $email,
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
            // ADR-0083 §4 — the journal records WHICH fields were set,
            // never their values: it is append-only and tamper-evident,
            // so a personal value written there could never be erased.
            after: [
                'operator_id' => $operator->id,
                'changed' => ['email', 'name'],
                'role' => $operator->role?->value,
            ],
            context: [],
            correlationId: $correlationId,
            occurredAt: $now,
        ));

        return new CreatedOperatorView($operator->id, $plainPassword);
    }
}
