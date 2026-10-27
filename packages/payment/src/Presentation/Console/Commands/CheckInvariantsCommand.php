<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Console\Commands;

use Core\Payment\Contracts\CheckPaymentInvariants;
use Illuminate\Console\Command as ConsoleCommand;

/**
 * ADR-0078 §2 — the hourly financial invariant check. Reads only; every
 * violation becomes a critical money exception in the console. Exit code 1
 * when a violation is found, so a scheduler or a person running it by hand
 * sees it at once. The commerce module's own checks join this command when
 * it exists (ADR-0078 §2).
 *
 * Every pass leaves a trace in the reconciliation register (ADR-0078 §5);
 * the identifier of that trace is displayed, so a person can prove the
 * check ran and passed.
 */
final class CheckInvariantsCommand extends ConsoleCommand
{
    protected $signature = 'invariants:check
        {--hours=2 : Look at payments and settlements changed in the last N hours}';

    protected $description = 'Check the financial invariants (ledger completeness, trial balance, wallets backed by collections).';

    public function handle(CheckPaymentInvariants $payments): int
    {
        $hoursOption = $this->option('hours');
        $hours = is_string($hoursOption) && ctype_digit($hoursOption) ? max(1, (int) $hoursOption) : 2;

        $report = $payments->check($hours);

        $this->info(sprintf(
            'Invariants : %d projet(s), %d paiement(s) vérifiés sur %d h — %d violation(s), %d exception(s) ouverte(s).',
            $report->projectsChecked,
            $report->paymentsChecked,
            $hours,
            count($report->violations),
            $report->exceptionsOpened,
        ));

        foreach ($report->runIds as $runId) {
            $this->line('  · passage de contrôle enregistré : '.$runId);
        }

        foreach ($report->violations as $violation) {
            $this->error('  · '.$violation);
        }

        return $report->isClean() ? self::SUCCESS : self::FAILURE;
    }
}
