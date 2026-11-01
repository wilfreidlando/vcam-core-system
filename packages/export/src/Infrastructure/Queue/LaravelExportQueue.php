<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Queue;

use Core\Export\Domain\Port\ExportQueue;

final class LaravelExportQueue implements ExportQueue
{
    public function push(string $exportId): void
    {
        RunExportJob::dispatch($exportId);
    }
}
