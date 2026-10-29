<?php

declare(strict_types=1);

namespace Core\Export\Infrastructure\Storage;

use Core\Export\Domain\Model\ProjectExport;
use Core\Export\Domain\Port\ExportStore;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

/**
 * One `.zip` of JSON Lines files, encrypted at rest (ADR-0088 §3).
 *
 * The archive is assembled in a private temporary directory, encrypted
 * chunk by chunk (`ChunkedEncryption`) and written to the `exports` disk
 * as `<project>/<export>.zip.enc`. The plain files never reach the disk
 * of the archive, and the temporary directory is removed whatever happens.
 */
final class EncryptedZipExportStore implements ExportStore
{
    public function __construct(
        private readonly ProjectDataExtractor $extractor,
        private readonly ChunkedEncryption $encryption,
        private readonly Factory $disks,
    ) {}

    public function build(ProjectExport $export, DateTimeImmutable $asOf): string
    {
        $directory = sys_get_temp_dir().'/core-export-'.$export->id;

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The export workspace could not be created.');
        }

        try {
            $counts = $this->extractor->extract($export->projectId, $export->id, $asOf, $directory);

            $zipPath = $directory.'/export.zip';
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The archive could not be created.');
            }

            $zip->addFile($directory.'/manifest.json', 'manifest.json');
            foreach (array_keys($counts) as $file) {
                $zip->addFile($directory.'/'.$file, $file);
            }

            if (! $zip->close()) {
                throw new RuntimeException('The archive could not be written.');
            }

            $encryptedPath = $directory.'/export.zip.enc';
            $this->encryptFile($zipPath, $encryptedPath);

            $path = $export->projectId.'/'.$export->id.'.zip.enc';
            $source = fopen($encryptedPath, 'rb');

            if ($source === false) {
                throw new RuntimeException('The encrypted archive could not be read.');
            }

            try {
                $this->disk()->writeStream($path, $source);
            } finally {
                fclose($source);
            }

            return $path;
        } finally {
            $this->remove($directory);
        }
    }

    public function delete(string $path): void
    {
        if ($this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }
    }

    public function stream(string $path, mixed $output): void
    {
        $input = $this->disk()->readStream($path);

        if (! is_resource($input)) {
            throw new RuntimeException('The export file is missing.');
        }

        try {
            $this->encryption->decrypt($input, $output);
        } finally {
            fclose($input);
        }
    }

    private function encryptFile(string $plainPath, string $encryptedPath): void
    {
        $plain = fopen($plainPath, 'rb');
        $encrypted = fopen($encryptedPath, 'wb');

        if ($plain === false || $encrypted === false) {
            throw new RuntimeException('The export could not be encrypted.');
        }

        try {
            $this->encryption->encrypt($plain, $encrypted);
        } finally {
            fclose($plain);
            fclose($encrypted);
        }
    }

    private function disk(): Filesystem
    {
        return $this->disks->disk('exports');
    }

    private function remove(string $directory): void
    {
        $files = glob($directory.'/*');

        foreach ($files === false ? [] : $files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
