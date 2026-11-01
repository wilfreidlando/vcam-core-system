<?php

declare(strict_types=1);

namespace Core\Audit\Presentation\Console\Commands;

use Core\Audit\Contracts\AuditDigests;
use Core\Support\Clock;
use Illuminate\Console\Command;

/** ADR-0081 S-16 — seals every complete day of the audit log not sealed yet. */
final class SealAuditLogCommand extends Command
{
    protected $signature = 'audit:seal';

    protected $description = 'Seal every complete day of the audit log with a chained SHA-256 digest.';

    public function handle(AuditDigests $digests, Clock $clock): int
    {
        $sealed = $digests->sealThrough($clock->now()->modify('-1 day'));

        $this->info(sprintf('%d jour(s) scellé(s).', count($sealed)));
        foreach ($sealed as $digest) {
            $this->line(sprintf('  %s · %d entrée(s) · %s', $digest->day, $digest->entriesCount, $digest->digest));
        }

        return self::SUCCESS;
    }
}
