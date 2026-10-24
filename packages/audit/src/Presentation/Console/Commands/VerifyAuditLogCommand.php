<?php

declare(strict_types=1);

namespace Core\Audit\Presentation\Console\Commands;

use Core\Audit\Contracts\AuditDigests;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** ADR-0081 S-16 — recomputes the digest chain; exit 1 if any sealed day was altered. */
final class VerifyAuditLogCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify that no sealed day of the audit log was altered.';

    public function handle(AuditDigests $digests): int
    {
        $result = $digests->verify();

        if ($result->isIntact()) {
            $this->info(sprintf('Journal d\'audit intact : %d jour(s) vérifié(s).', $result->daysChecked));

            return self::SUCCESS;
        }

        // A scheduled run has no reader: the critical log is what pages someone.
        Log::critical('Audit log tampering detected: sealed days no longer match their digest (ADR-0081 S-16).', [
            'broken_days' => $result->brokenDays,
            'days_checked' => $result->daysChecked,
        ]);

        $this->error(sprintf('Journal d\'audit ALTÉRÉ : %d jour(s) en écart sur %d.', count($result->brokenDays), $result->daysChecked));
        foreach ($result->brokenDays as $day) {
            $this->error('  · '.$day);
        }

        return self::FAILURE;
    }
}
