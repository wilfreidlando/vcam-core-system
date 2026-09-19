<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\RevokeOperator as RevokeOperatorPort;
use Core\Identity\Contracts\RevokeOperatorCommand;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Support\Clock;

final readonly class RevokeOperator implements RevokeOperatorPort
{
    public function __construct(
        private OperatorRepository $operators,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function revoke(RevokeOperatorCommand $command, string $actor, ?string $correlationId): void
    {
        $operator = $this->operators->find($command->operatorId)
            ?? throw OperatorNotFound::withId($command->operatorId);

        $wasAlreadyRevoked = $operator->isRevoked();

        $now = $this->clock->now();
        $operator->revoke($now);
        $this->operators->save($operator);

        if ($wasAlreadyRevoked) {
            // Idempotent no-op (Operator::revoke()'s own ??= semantics) —
            // don't write a second, misleading audit entry for a
            // revocation that already happened.
            return;
        }

        $this->audit->record(new AuditEntry(
            actor: $actor,
            action: 'operator.revoked',
            resource: 'operator:'.$operator->id,
            before: null,
            after: null,
            context: [],
            correlationId: $correlationId,
            occurredAt: $now,
        ));
    }
}
