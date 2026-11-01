<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\MetricsSnapshotStore;
use Core\Billing\Contracts\ProjectMetrics;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Throwable;

/**
 * ADR-0080 §3 — the nightly pass: the indicators of every project, kept for
 * the day.
 *
 * Replayable: a second pass the same day overwrites the day's rows, and a
 * pass over a **range** of days fills the days the nightly job missed.
 *
 * The figures are those of **now** — MRR and the counters of a past day
 * cannot be rebuilt from the present state, and ADR-0080 rejected a
 * history that changes when the data changes. The instant of the
 * computation is kept in `computed_at`: a row computed on another day says
 * so on the curve, so a reconstructed day is never read as an observed one.
 *
 * One failing project is skipped, never fatal to the others.
 */
final readonly class SnapshotMetricsUseCase
{
    /** The widest range a single pass may write. */
    private const MAX_DAYS = 366;

    public function __construct(
        private MetricsSnapshotStore $store,
        private ProjectMetrics $metrics,
        private Clock $clock,
    ) {}

    /**
     * @param string|null $from the first day to write (inclusive); today when omitted
     * @param string|null $to the last day to write (inclusive); today when omitted
     * @return array{projects: int, failed: int, days: int}
     */
    public function snapshot(?string $from = null, ?string $to = null): array
    {
        $days = $this->days($from, $to);
        $done = 0;
        $failed = 0;

        foreach ($this->store->projectsToSnapshot() as $projectId) {
            try {
                $view = $this->metrics->forProject($projectId, AccessScope::platform());

                if ($view === null) {
                    continue;
                }

                foreach ($days as $day) {
                    $this->store->save($day, $view);
                }

                $done++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        return ['projects' => $done, 'failed' => $failed, 'days' => count($days)];
    }

    /**
     * The days to write, oldest first.
     *
     * @return list<string>
     */
    private function days(?string $from, ?string $to): array
    {
        $today = $this->clock->now()->format('Y-m-d');
        $last = $to ?? $today;
        $first = $from ?? $last;

        try {
            $start = new DateTimeImmutable($first, new DateTimeZone('UTC'));
            $end = new DateTimeImmutable($last, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            throw new InvalidArgumentException(
                'The days of a snapshot range are written as YYYY-MM-DD.',
                previous: $e,
            );
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $span = $start->diff($end)->days;

        if ($span >= self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'A snapshot pass covers at most %d days; %d were asked for.',
                self::MAX_DAYS,
                $span + 1,
            ));
        }

        $days = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }
}
