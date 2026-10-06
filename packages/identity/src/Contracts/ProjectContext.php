<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Environment;

/**
 * "Who is calling, in which environment, with which scopes" — resolved once
 * per request by `ResolveProjectMiddleware` and bound as a scoped singleton.
 * Every future capability module's controllers depend on THIS, never on
 * `Core\Identity\Domain\Model\Project` directly (that class lives behind
 * `Contracts/`, per the module-boundary rule G3c).
 */
final readonly class ProjectContext
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $projectId,
        public string $organizationId,
        public Environment $environment,
        public array $scopes,
        // The visible part of the key that authenticated the request
        // (never the secret) — for logs and the payment "Parcours".
        public string $apiKeyPrefix = '',
    ) {}

    public function hasScope(string $scope): bool
    {
        // `*` is the wildcard — mirrors `ApiKey::hasScope()` and
        // `OperatorRole::hasPermission()` so a Project key issued
        // with `--scope='*'` covers every concrete scope. The
        // three call sites form a single rule: wildcard = "this key
        // can do everything its tenant is allowed to do".
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
