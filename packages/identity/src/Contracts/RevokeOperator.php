<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use RuntimeException;

interface RevokeOperator
{
    /**
     * Idempotent: revoking an already-revoked operator is a no-op, not an
     * error (mirrors `Operator::revoke()`'s own `??=` semantics).
     *
     * @throws RuntimeException if the operator does not exist. The
     *                          concrete exception type lives in the Domain
     *                          layer; Contracts must not reference it (G3c).
     */
    public function revoke(RevokeOperatorCommand $command, string $actor, ?string $correlationId): void;
}
