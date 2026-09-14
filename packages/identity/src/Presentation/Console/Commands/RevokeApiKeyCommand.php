<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Application\Dto\RevokeApiKeyCommand as Command;
use Core\Identity\Application\UseCase\RevokeApiKey;
use Core\Identity\Domain\Exception\ApiKeyNotFound;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

final class RevokeApiKeyCommand extends ConsoleCommand
{
    protected $signature = 'identity:revoke-api-key {apiKey : The API key id}';

    protected $description = 'Revoke an API key immediately';

    public function handle(RevokeApiKey $revokeApiKey): int
    {
        try {
            $revokeApiKey(
                new Command(apiKeyId: $this->argument('apiKey')),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (ApiKeyNotFound $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('API key revoked.');

        return self::SUCCESS;
    }
}
