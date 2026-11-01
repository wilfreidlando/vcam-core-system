<?php

declare(strict_types=1);

namespace Core\Export\Contracts;

/**
 * The download of an export, behind a signed link: the signature is the only
 * authentication, so every download is audited.
 */
interface DownloadExport
{
    /**
     * @throws ExportNotFound
     * @throws ExportNotAvailable the export is not finished, failed, or was deleted
     */
    public function prepare(string $exportId): ExportDownloadView;

    /**
     * Writes the decrypted archive to `$output`, a chunk at a time.
     *
     * @param resource $output
     *
     * @throws ExportNotFound
     * @throws ExportNotAvailable
     */
    public function stream(string $exportId, mixed $output): void;
}
