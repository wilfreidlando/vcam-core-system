<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

/**
 * Builds the file of a queued export. Idempotent: an export that is not
 * `queued` any more is left alone, so a replayed job builds nothing twice.
 */
interface RunExport
{
    public function run(string $exportId): void;
}
