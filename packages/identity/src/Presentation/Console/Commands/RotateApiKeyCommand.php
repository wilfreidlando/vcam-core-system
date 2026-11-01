<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\RotateApiKey;
use Core\Identity\Contracts\RotateApiKeyCommand as Command;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;
use RuntimeException;

/** ADR-0082 §3 — replace a key without an outage. */
final class RotateApiKeyCommand extends ConsoleCommand
{
    protected $signature = 'identity:rotate-api-key
        {apiKey : The id of the key to replace}
        {--grace-days=7 : Days the old key stays valid (1 to 30)}';

    protected $description = 'Issue a replacement for an API key; the old one stops working after the grace period.';

    public function handle(RotateApiKey $rotateApiKey): int
    {
        $graceDays = $this->option('grace-days');

        try {
            $issued = $rotateApiKey->rotate(
                new Command(
                    apiKeyId: $this->argument('apiKey'),
                    graceDays: is_numeric($graceDays) ? (int) $graceDays : Command::DEFAULT_GRACE_DAYS,
                ),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Replacement issued: {$issued->apiKeyId} (prefix {$issued->prefix}). Deploy it, the old key stops working at the end of the grace period.");
        $this->newLine();
        $this->warn('This key is shown once and cannot be retrieved again:');
        $this->line($issued->plaintextToken);

        return self::SUCCESS;
    }
}
