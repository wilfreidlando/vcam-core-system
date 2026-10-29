<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * ADR-0080 — the numbers the console shows. The scope is **required**
 * (ADR-0069 §2): a project outside it yields `null`, never a zero that would
 * look like an empty project.
 */
interface ProjectMetrics
{
    public function forProject(string $projectId, AccessScope $scope): ?ProjectMetricsView;
}
