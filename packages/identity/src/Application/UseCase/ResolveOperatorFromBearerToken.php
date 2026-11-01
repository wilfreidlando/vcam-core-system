<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\ResolveAccessScope;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OperatorTokenResolver;

/**
 * The operator authentication path every admin-console request runs
 * through (`ResolveOperatorMiddleware` calls this once per request) —
 * mirrors `ResolveProjectFromBearerToken`. Deliberately unaudited, same
 * reasoning as its Project counterpart.
 */
final readonly class ResolveOperatorFromBearerToken
{
    public function __construct(
        private OperatorTokenResolver $tokens,
        private OperatorRepository $operators,
        private ResolveAccessScope $scopes,
    ) {}

    public function __invoke(string $bearerToken): OperatorContext
    {
        $operatorId = $this->tokens->resolve($bearerToken)
            ?? throw InvalidOperatorCredentials::unknown();

        $operator = $this->operators->find($operatorId)
            ?? throw InvalidOperatorCredentials::unknown();

        if ($operator->isRevoked()) {
            throw OperatorRevoked::withId($operator->id);
        }

        return new OperatorContext(
            operatorId: $operator->id,
            email: $operator->email,
            role: $operator->role,
            // ADR-0069 §2 — what this account may see.
            scope: $this->scopes->forOperator($operator->id),
        );
    }
}
