<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

/**
 * Wraps Sanctum's `HasApiTokens::createToken()` — an Eloquent-model
 * method, so it cannot be called from Domain/Application directly (G3b) —
 * behind a port an Infrastructure adapter implements.
 */
interface OperatorTokenIssuer
{
    /**
     * @return string the plaintext bearer token, shown once
     */
    public function issue(string $operatorId, string $tokenName): string;
}
