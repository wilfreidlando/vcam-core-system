<?php

declare(strict_types=1);

namespace Core\Support\Privacy\Presentation\Console\Commands;

use Illuminate\Console\Command;

/**
 * ADR-0083 §2 — generates the two keys of the platform. The operator
 * copies them into the deployment environment (ADR-0064); they never
 * enter the repository, and the database holds only what was encrypted.
 */
final class GeneratePrivacyKeyCommand extends Command
{
    protected $signature = 'privacy:generate-key
        {--blind-index : Generate the blind-index key instead of the encryption key}
        {--key-version= : Version of the encryption key (default: 1)}';

    protected $description = 'Generate a personal-data encryption key or a blind-index key for the deployment environment.';

    public function handle(): int
    {
        if ($this->option('blind-index')) {
            $this->line('PII_BLIND_INDEX_KEY=base64:'.base64_encode(random_bytes(32)));

            return self::SUCCESS;
        }

        $version = $this->option('key-version');
        $version = is_numeric($version) ? (string) (int) $version : '1';

        $this->line(sprintf('PII_ENCRYPTION_KEYS=v%s:base64:%s', $version, base64_encode(random_bytes(32))));
        $this->newLine();
        $this->warn('Keep the previous versions in the list while the rows are being re-encrypted.');
        $this->warn('This key is shown once: store it in the deployment environment now.');

        return self::SUCCESS;
    }
}
