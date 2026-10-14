<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand as Command;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A bootstrap tool, kept alongside the admin console's self-service
 * `POST /v1/admin/projects` (both converge on the same
 * `Contracts\CreateProject` port) — useful for scripting/seeding without
 * going through HTTP.
 */
final class CreateProjectCommand extends ConsoleCommand
{
    protected $signature = 'identity:create-project {organization : The Organization id} {name : The project name}';

    protected $description = 'Create a new Project under an Organization';

    public function handle(CreateProject $createProject): int
    {
        try {
            $provisioned = $createProject->create(
                new Command(
                    organizationId: $this->argument('organization'),
                    name: $this->argument('name'),
                ),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Project created: {$provisioned->project->id}");
        $this->warn('The API key below is shown once — copy it now, it cannot be retrieved again:');
        $this->line("  {$provisioned->apiKey->plaintextToken}");

        return self::SUCCESS;
    }
}
