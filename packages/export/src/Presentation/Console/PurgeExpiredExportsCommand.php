<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Console;

use Core\Export\Contracts\PurgeExpiredExports;
use Illuminate\Console\Command;

/**
 * ADR-0088 §3 — the files of an export are deleted seven days after the
 * request. Scheduled daily; idempotent (the record stays, the file goes once).
 */
final class PurgeExpiredExportsCommand extends Command
{
    protected $signature = 'exports:purge-expired';

    protected $description = 'Delete the files of the exports whose seven days are over (ADR-0088 §3).';

    public function handle(PurgeExpiredExports $purge): int
    {
        $this->info(sprintf('%d export file(s) deleted.', $purge->purge()));

        return self::SUCCESS;
    }
}
