<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\MetricsSnapshotStore;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Throwable;

/**
 * ADR-0080 §2 — the nightly pass: the indicators of every project, kept for the day.
 *
 * Replayable: a second pass the same day overwrites the day's rows. The figures
 * are those of **now** — MRR and the counters of a past day cannot be rebuilt
 * from the present state, so the pass records the day it runs and never
 * backfills. One failing project is skipped, never fatal to the others.
 */
final readonly class SnapshotMetricsUseCase
{
    public function __construct(
        private MetricsSnapshotStore $store,
        private ProjectMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * @return array{projects: int, failed: int}
     */
    public function snapshot(): array
    {
        $day = $this->clock->now()->format('Y-m-d');
        $done = 0;
        $failed = 0;

        foreach ($this->store->projectsToSnapshot() as $projectId) {
            try {
                $view = $this->metrics->forProject($projectId, AccessScope::platform());

                if ($view === null) {
                    continue;
                }

                $this->store->save($day, $view);
                $done++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        return ['projects' => $done, 'failed' => $failed];
    }
}
