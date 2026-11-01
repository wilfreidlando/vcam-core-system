<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand as Command;
use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;
use RuntimeException;

/** ADR-0082 — issue one more named key for a project. */
final class IssueApiKeyCommand extends ConsoleCommand
{
    protected $signature = 'identity:issue-api-key
        {project : The Project id}
        {--name=Clé principale : A name telling what uses the key}
        {--template= : server, read_only, portal or payouts (instead of --scope)}
        {--scope=* : A scope to grant, repeatable (e.g. --scope=payments:write)}
        {--cidr=* : Allowed network block, repeatable (e.g. --cidr=203.0.113.0/24)}
        {--expires-at= : Optional expiry (ISO 8601)}';

    protected $description = 'Issue a named, scoped API key for a Project. Shown once, never stored.';

    public function handle(IssueApiKey $issueApiKey): int
    {
        /** @var list<string> $scopes */
        $scopes = $this->option('scope');
        $template = $this->option('template');
        if (is_string($template) && $template !== '') {
            if (! array_key_exists($template, ApiKeyScopes::TEMPLATES)) {
                $this->error('Unknown template. Use one of: '.implode(', ', array_keys(ApiKeyScopes::TEMPLATES)).'.');

                return self::FAILURE;
            }
            $scopes = array_values(array_unique([...ApiKeyScopes::template($template), ...$scopes]));
        }

        /** @var list<string> $cidrs */
        $cidrs = $this->option('cidr');
        $name = $this->option('name');
        $expiresAt = $this->option('expires-at');

        try {
            $issued = $issueApiKey->issue(
                new Command(
                    projectId: $this->argument('project'),
                    scopes: $scopes,
                    name: is_string($name) ? $name : 'Clé principale',
                    allowedCidrs: $cidrs,
                    expiresAt: is_string($expiresAt) && $expiresAt !== '' ? new DateTimeImmutable($expiresAt) : null,
                ),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (RuntimeException|Exception $e) {
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
