<?php

declare(strict_types=1);

namespace Core\Export\Domain\Port;

use Core\Export\Domain\Model\ProjectExport;
use DateTimeImmutable;

/**
 * Builds the file of an export, keeps it encrypted at rest, gives it back.
 */
interface ExportStore
{
    /**
     * Writes the project's data as of `$asOf` into one archive, encrypts it
     * and stores it.
     *
     * @return string the path the archive was stored under
     */
    public function build(ProjectExport $export, DateTimeImmutable $asOf): string;

    /**
     * Deletes a stored archive. Idempotent: a missing file is not an error.
     */
    public function delete(string $path): void;

    /**
     * Decrypts the archive into `$output`, a chunk at a time.
     *
     * @param resource $output
     */
    public function stream(string $path, mixed $output): void;
}
