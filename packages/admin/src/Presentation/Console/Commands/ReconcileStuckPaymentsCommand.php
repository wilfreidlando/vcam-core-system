<?php

declare(strict_types=1);

namespace Core\Admin\Presentation\Console\Commands;

use Core\Payment\Contracts\ReconcileStuckPayments;
use Core\Payment\Contracts\ReconcileStuckPaymentsCommand as ReconcileCommand;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes (bootstrap/app.php) — the primary delivery
 * mechanism for reconciliation (spec technique §10). Uses the full
 * configured batch size, unlike the on-demand HTTP trigger.
 */
final class ReconcileStuckPaymentsCommand extends Command
{
    protected $signature = 'admin:reconcile-stuck-payments';

    protected $description = 'Reconcile payments stuck in a non-terminal status by polling checkStatus() on their provider.';

    public function handle(ReconcileStuckPayments $reconcileStuckPayments): int
    {
        $summary = $reconcileStuckPayments->reconcile(new ReconcileCommand);

        $this->info(sprintf(
            'Reconciliation: %d checked, %d updated, %d errors.',
            $summary->checked,
            $summary->updated,
            $summary->errors,
        ));

        return self::SUCCESS;
    }
}
