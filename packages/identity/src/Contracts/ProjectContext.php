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
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
