<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

/**
 * ADR-0088 §3 — deletes the files whose seven days are over. Idempotent:
 * the record stays, the file goes once.
 */
interface PurgeExpiredExports
{
    /**
     * @return int the number of files deleted by this run
     */
    public function purge(): int;
}
