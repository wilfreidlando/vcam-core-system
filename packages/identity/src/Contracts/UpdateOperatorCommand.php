<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `role` is a raw canonical role string (e.g. `"finance_operator"`), not
 * the `OperatorRole` enum — same reasoning as `CreateOperatorCommand`: the
 * enum lives top-level, outside `Contracts/`, so a Contracts DTO typed
 * with it would be unusable from outside this module (G3c). The use case
 * converts it via `OperatorRole::tryFrom()`, throwing `InvalidOperatorRole`
 * on an unrecognised value.
 *
 * ADR-0069 §1: an **empty** role means the account has no platform role
 * at all — it then sees only the organizations it belongs to.
 */
final readonly class UpdateOperatorCommand
{
    public function __construct(
        public string $operatorId,
        public string $email,
        public string $name,
        public string $role,
    ) {}
}
