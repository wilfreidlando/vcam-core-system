<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

use RuntimeException;

/**
 * The export has no file to give: it is not finished, it failed, or its
 * seven days are over and the file was deleted.
 */
final class ExportNotAvailable extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Export {$id} has no file to download: it is not finished, it failed, or its retention is over.");
    }
}
