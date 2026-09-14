<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Application\Dto\CreateProjectCommand as Command;
use Core\Identity\Application\UseCase\CreateProject;
use Core\Identity\Domain\Exception\OrganizationNotFound;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

final class CreateProjectCommand extends ConsoleCommand
{
    protected $signature = 'identity:create-project {organization : The Organization id} {name : The project name}';

    protected $description = 'Create a new Project under an Organization';

    public function handle(CreateProject $createProject): int
    {
        try {
            $project = $createProject(
                new Command(
                    organizationId: $this->argument('organization'),
                    name: $this->argument('name'),
                ),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (OrganizationNotFound $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Project created: {$project->id}");

        return self::SUCCESS;
    }
}
