<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

interface OperatorTokenRevoker
{
    /**
     * @return string|null the operator id the token belonged to, or null
     *                     if the token was already unknown
     */
    public function revoke(string $plaintextToken): ?string;
}
