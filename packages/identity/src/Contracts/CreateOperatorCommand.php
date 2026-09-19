<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `role` is a raw canonical role string (e.g. `"finance_operator"`), not
 * the `OperatorRole` enum — same reasoning as `OperatorSummaryView`: the
 * enum lives top-level, outside `Contracts/`, so a Contracts DTO typed
 * with it would be unusable from outside this module (G3c). The use case
 * converts it via `OperatorRole::tryFrom()`, throwing `InvalidOperatorRole`
 * on an unrecognised value — this one really is a validation error (unlike
 * an unrecognised status filter elsewhere), since a role must be valid for
 * the created operator to mean anything.
 */
final readonly class CreateOperatorCommand
{
    public function __construct(
        public string $email,
        public string $name,
        public string $role,
    ) {}
}
