<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Contracts\MetricsPoint;
use Core\Billing\Contracts\ProjectMetricsView;

/**
 * Where the nightly indicators are kept — a persistence concern.
 */
interface MetricsSnapshotStore
{
    /**
     * Writes the day's rows for a project, replacing the ones already there
     * (the pass is replayable).
     */
    public function save(string $day, ProjectMetricsView $metrics): void;

    /**
     * @return list<MetricsPoint> the project's rows from `$sinceDay` (inclusive), oldest first
     */
    public function since(string $projectId, string $sinceDay): array;

    /**
     * @return list<string> the projects the pass has to look at
     */
    public function projectsToSnapshot(): array;
}
