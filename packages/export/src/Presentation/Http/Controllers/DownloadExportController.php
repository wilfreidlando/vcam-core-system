<?php

declare(strict_types=1);

namespace Core\Export\Presentation\Http\Controllers;

use Core\Export\Contracts\DownloadExport;
use Core\Export\Contracts\ExportNotAvailable;
use Core\Export\Contracts\ExportNotFound;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /exports/{exportId}/download` — the signed link of ADR-0088 §3.
 *
 * Authenticated by the signature alone (the `signed` middleware): an
 * altered or expired link is a `403` before this controller runs. The
 * archive is decrypted while it streams, one chunk at a time.
 */
final class DownloadExportController
{
    public function __construct(
        private readonly DownloadExport $download,
    ) {}

    /**
     * @throws ExportNotFound
     * @throws ExportNotAvailable
     */
    public function __invoke(string $exportId): StreamedResponse
    {
        // Refuses (404 / 409) before any byte is sent.
        $file = $this->download->prepare($exportId);

        return new StreamedResponse(function () use ($exportId): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            try {
                $this->download->stream($exportId, $output);
            } finally {
                fclose($output);
            }
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$file->filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
