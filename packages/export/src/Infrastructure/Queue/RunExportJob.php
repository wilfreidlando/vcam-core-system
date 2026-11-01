<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Queue;

use Core\Export\Contracts\RunExport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the file of one export, outside the request (ADR-0088 §3).
 *
 * One try: the use case records a failure on the export itself, and a
 * replayed job does nothing once the export is no longer `queued`.
 */
final class RunExportJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly string $exportId) {}

    public function handle(RunExport $run): void
    {
        $run->run($this->exportId);
    }
}
