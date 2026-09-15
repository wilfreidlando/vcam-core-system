<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\LogoutOperatorCommand;
use Core\Identity\Domain\Port\OperatorTokenRevoker;
use Core\Support\Clock;

final readonly class LogoutOperator
{
    public function __construct(
        private OperatorTokenRevoker $tokens,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function __invoke(LogoutOperatorCommand $command): void
    {
        $operatorId = $this->tokens->revoke($command->bearerToken);

        if ($operatorId === null) {
            return;
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operatorId,
            action: 'operator.logout',
            resource: 'operator:'.$operatorId,
            before: null,
            after: null,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));
    }
}
