<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand as Command;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Support\Environment;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;
use ValueError;

final class IssueApiKeyCommand extends ConsoleCommand
{
    protected $signature = 'identity:issue-api-key
        {project : The Project id}
        {environment : sandbox or live}
        {--scope=* : A scope to grant, repeatable (e.g. --scope=payments:write)}';

    protected $description = 'Issue a new API key for a Project. The plaintext key is shown once and never stored.';

    public function handle(IssueApiKey $issueApiKey): int
    {
        try {
            $environment = Environment::from($this->argument('environment'));
        } catch (ValueError) {
            $this->error('environment must be "sandbox" or "live".');

            return self::FAILURE;
        }

        /** @var list<string> $scopes */
        $scopes = $this->option('scope');

        try {
            $issued = $issueApiKey->issue(
                new Command(
                    projectId: $this->argument('project'),
                    environment: $environment,
                    scopes: $scopes,
                ),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (ProjectNotFound|ProjectNotActive $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("API key issued: {$issued->apiKeyId} (prefix {$issued->prefix})");
        $this->newLine();
        $this->warn('This key is shown once and cannot be retrieved again:');
        $this->line($issued->plaintextToken);

        return self::SUCCESS;
    }
}
