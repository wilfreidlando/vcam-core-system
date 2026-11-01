<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\RevokeApiKey;
use Core\Identity\Contracts\RevokeApiKeyCommand as Command;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;
use RuntimeException;

final class RevokeApiKeyCommand extends ConsoleCommand
{
    protected $signature = 'identity:revoke-api-key
        {apiKey : The API key id}
        {--reason= : Why the key is revoked (required, audited)}';

    protected $description = 'Revoke an API key immediately';

    public function handle(RevokeApiKey $revokeApiKey): int
    {
        $reason = $this->option('reason');

        try {
            $revokeApiKey->revoke(
                new Command(apiKeyId: $this->argument('apiKey'), reason: is_string($reason) ? $reason : ''),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('API key revoked.');

        return self::SUCCESS;
    }
}
