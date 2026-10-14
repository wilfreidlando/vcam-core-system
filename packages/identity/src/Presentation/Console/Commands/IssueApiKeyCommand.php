<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand as Command;
use Core\Identity\Domain\Exception\ApiKeyAlreadyExists;
use Core\Identity\Domain\Exception\ProjectNotActive;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

final class IssueApiKeyCommand extends ConsoleCommand
{
    protected $signature = 'identity:issue-api-key
        {project : The Project id}
        {--scope=* : A scope to grant, repeatable (e.g. --scope=payments:write)}
        {--replace : Regenerate: replace the existing key of the project (ADR-0066)}';

    protected $description = 'Issue (or, with --replace, regenerate) the API key of a Project. Shown once, never stored.';

    public function handle(IssueApiKey $issueApiKey): int
    {
        /** @var list<string> $scopes */
        $scopes = $this->option('scope');

        try {
            $issued = $issueApiKey->issue(
                new Command(
                    projectId: $this->argument('project'),
                    scopes: $scopes,
                    replaceExisting: $this->option('replace') === true,
                ),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (ProjectNotFound|ProjectNotActive|ApiKeyAlreadyExists $e) {
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
