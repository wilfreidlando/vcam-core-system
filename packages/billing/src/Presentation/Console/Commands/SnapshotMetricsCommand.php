<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Console\Commands;

use Core\Billing\Application\UseCase\SnapshotMetricsUseCase;
use Illuminate\Console\Command;

/**
 * ADR-0080 §2 — keeps the day's indicators, scheduled once a night in
 * `bootstrap/app.php`. Idempotent: replayed the same day it overwrites the
 * day's rows.
 *
 * ADR-0080 §3 — the pass is also **recalculable over a range**: `--from`
 * and `--to` fill the days the nightly job missed. The figures written are
 * those of the moment of the pass; `computed_at` keeps that instant, so the
 * console says a reconstructed day apart from an observed one.
 */
final class SnapshotMetricsCommand extends Command
{
    protected $signature = 'billing:snapshot-metrics
        {--from= : Premier jour à recalculer (YYYY-MM-DD) — par défaut, le dernier jour}
        {--to= : Dernier jour à recalculer (YYYY-MM-DD) — par défaut, aujourd\'hui}';

    protected $description = 'Keep the indicators of every project, for the console curves.';

    public function handle(SnapshotMetricsUseCase $snapshot): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        $report = $snapshot->snapshot(
            is_string($from) && $from !== '' ? $from : null,
            is_string($to) && $to !== '' ? $to : null,
        );

        $this->info(sprintf(
            'Indicateurs : %d projet(s) enregistré(s), %d en échec, %d jour(s).',
            $report['projects'],
            $report['failed'],
            $report['days'],
        ));

        return self::SUCCESS;
    }
}
