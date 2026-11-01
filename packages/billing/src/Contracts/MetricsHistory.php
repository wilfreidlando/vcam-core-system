<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0080 §2 — the indicators of past days, for the curves of the console.
 * The scope is **required** (ADR-0069 §2): a project outside it yields an
 * empty history.
 */
interface MetricsHistory
{
    /**
     * @return list<MetricsPoint> the last `$days` days (1 to 366), oldest first
     */
    public function forProject(string $projectId, AccessScope $scope, int $days): array;
}
