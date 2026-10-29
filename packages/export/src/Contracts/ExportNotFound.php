<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

use RuntimeException;

/**
 * The export does not exist — or belongs to another project: the answer is
 * the same, so that the identifier of another tenant is never confirmed.
 */
final class ExportNotFound extends RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Export {$id} was not found.");
    }
}
