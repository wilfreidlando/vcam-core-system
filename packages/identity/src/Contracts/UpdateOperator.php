<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface UpdateOperator
{
    /**
     * Never touches credentials (password) or MFA state — this action is
     * profile-only (email/name/role).
     *
     * @throws RuntimeException if `$command->operatorId` doesn't resolve
     *                          to an existing Operator, or `$command->role`
     *                          isn't a recognised `OperatorRole` value. The
     *                          concrete exception types live in the Domain
     *                          layer; Contracts must not reference them
     *                          (G3c).
     */
    public function update(UpdateOperatorCommand $command, string $actor, ?string $correlationId): void;
}
