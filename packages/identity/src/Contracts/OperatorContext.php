<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Identity\OperatorRole;

/**
 * "Which operator is calling, with which role" — resolved once per request
 * by `ResolveOperatorMiddleware` and bound as a scoped singleton. Mirrors
 * `ProjectContext` for the machine-identity plane; `admin`'s controllers
 * depend on THIS, never on `Core\Identity\Domain\Model\Operator` directly
 * (G3c).
 */
final readonly class OperatorContext
{
    public function __construct(
        public string $operatorId,
        public string $email,
        public OperatorRole $role,
    ) {}

    public function hasPermission(string $permission): bool
    {
        return $this->role->hasPermission($permission);
    }
}
