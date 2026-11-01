<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0071 §1 — the version a project asks for is not one of its plan's.
 *
 * The rank of a version is stable and unique inside its plan
 * (`plan_versions_plan_version_unique`): asking for a rank that does not
 * exist is a lookup that failed, not a transition.
 */
final class PlanVersionNotFound extends RuntimeException
{
    public static function becauseVersion(string $planCode, int $version): self
    {
        return new self("The plan \"{$planCode}\" has no version {$version}.");
    }
}
