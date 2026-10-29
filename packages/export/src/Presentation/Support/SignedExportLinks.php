<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Support;

use Core\Export\Contracts\SignedDownloadLinks;
use Illuminate\Support\Facades\URL;

/**
 * ADR-0088 §3 — a temporary signed route: the signature is the only
 * authentication of the download, and it expires after fifteen minutes.
 */
final class SignedExportLinks implements SignedDownloadLinks
{
    public function forExport(string $exportId): string
    {
        return URL::temporarySignedRoute(
            'exports.download',
            now()->addMinutes(self::VALIDITY_MINUTES),
            ['exportId' => $exportId],
        );
    }
}
