<?php

declare(strict_types=1);

namespace Core\Export\Domain\Port;

interface ExportQueue
{
    /**
     * Hands the export to the queue: it is built outside the request.
     */
    public function push(string $exportId): void;
}
