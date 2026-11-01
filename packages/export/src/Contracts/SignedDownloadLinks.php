<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

/**
 * ADR-0088 §3 — the signed link of an export, valid fifteen minutes.
 */
interface SignedDownloadLinks
{
    /** Minutes a download link stays valid. */
    public const VALIDITY_MINUTES = 15;

    public function forExport(string $exportId): string;
}
