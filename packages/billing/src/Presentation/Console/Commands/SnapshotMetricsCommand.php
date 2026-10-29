<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Console\Commands;

use Core\Billing\Application\UseCase\SnapshotMetricsUseCase;
use Illuminate\Console\Command;

/**
 * ADR-0080 §2 — keeps the day's indicators, scheduled once a night in
 * `bootstrap/app.php`. Idempotent: replayed the same day it overwrites the
 * day's rows.
 */
final class SnapshotMetricsCommand extends Command
{
    protected $signature = 'billing:snapshot-metrics';

    protected $description = 'Keep today\'s indicators of every project, for the console curves.';

    public function handle(SnapshotMetricsUseCase $snapshot): int
    {
        $report = $snapshot->snapshot();

        $this->info(sprintf('Indicateurs : %d projet(s) enregistré(s), %d en échec.', $report['projects'], $report['failed']));

        return self::SUCCESS;
    }
}
