<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Console\Commands;

use Core\Billing\Application\UseCase\AdvanceSubscriptionsUseCase;
use Illuminate\Console\Command;

/**
 * ADR-0072 §8 — the scheduler of the contracts.
 *
 * **Scheduled every five minutes**, with `onOneServer()` (one server
 * runs the pass, the others do not) and `withoutOverlapping()` (a pass
 * that is still running is not started again). The registration lives in
 * `bootstrap/app.php`, next to the other schedules of the Core:
 *
 * ```php
 * $schedule->command('billing:advance-subscriptions')
 *     ->everyFiveMinutes()
 *     ->onOneServer()
 *     ->withoutOverlapping();
 * ```
 *
 * The command is **idempotent**: replayed, it creates neither a
 * duplicate period nor a duplicate event (ADR-0072 §8). The uniqueness
 * of `(subscription_id, seq)` and the exclusion constraint of
 * `subscription_periods` are the backstop.
 */
final class AdvanceSubscriptionsCommand extends Command
{
    protected $signature = 'billing:advance-subscriptions
        {--limit=500 : Maximum number of contracts examined in one pass}';

    protected $description = 'Advance the subscription contracts whose next step is due (renewal preparation, period rollover, expiry).';

    public function handle(AdvanceSubscriptionsUseCase $advance): int
    {
        $limitOption = $this->option('limit');
        $limit = is_string($limitOption) && ctype_digit($limitOption) ? max(1, (int) $limitOption) : 500;

        $report = $advance->advance($limit);

        $this->info(sprintf(
            'Abonnements : %d contrat(s) examiné(s), %d renouvellement(s) préparé(s), %d renouvelé(s), %d activé(s), %d en retard, %d expiré(s), %d annulé(s).',
            $report->examined,
            $report->renewalsPrepared,
            $report->renewed,
            $report->activated,
            $report->pastDue,
            $report->expired,
            $report->canceled,
        ));

        if ($report->failed > 0) {
            // The contracts that could not be advanced are logged one by
            // one; the pass itself is a success — it did advance the
            // others. A non-zero exit would make the scheduler report a
            // failure for a single stubborn contract.
            $this->warn(sprintf(
                '%d contrat(s) n\'ont pas pu être avancés (voir le journal).',
                $report->failed,
            ));
        }

        return self::SUCCESS;
    }
}
