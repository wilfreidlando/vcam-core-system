<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use LogicException;

/**
 * Two projects of one organisation, each with a key of the scopes the
 * test asks for — the setup of every « a key never sees another
 * project's rows » test.
 */
trait ProjectsWithKeys
{
    /**
     * @return array{organization_id: string, project_id: string}
     */
    protected function makeProject(string $name, ?string $organizationId = null): array
    {
        if ($organizationId === null) {
            $organizationId = app(CreateOrganization::class)
                ->create(new CreateOrganizationCommand($name.' Org'), actor: 'service:test', correlationId: null)->id;
        }

        $projectId = app(CreateProject::class)
            ->create(new CreateProjectCommand($organizationId, $name), actor: 'service:test', correlationId: null)->project->id;

        return ['organization_id' => $organizationId, 'project_id' => $projectId];
    }

    /**
     * @param list<string> $scopes
     * @return array<string, string> the headers of a call made with the new key
     */
    protected function keyFor(string $projectId, array $scopes, string $name = 'Test'): array
    {
        $key = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($projectId, $scopes, name: $name),
            actor: 'service:test',
            correlationId: null,
        );

        return ['Authorization' => 'Bearer '.$key->plaintextToken];
    }

    /**
     * A JSON scalar as a string — the typed way to read a response.
     */
    protected static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : throw new LogicException('A scalar was expected.');
    }

    /**
     * A JSON object as an array.
     *
     * @return array<string, mixed>
     */
    protected static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('An object was expected.');
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * A JSON list of objects.
     *
     * @return list<array<string, mixed>>
     */
    protected static function rows(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('A list was expected.');
        }

        return array_values(array_map(static fn (mixed $row): array => self::map($row), $value));
    }
}
