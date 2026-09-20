<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Unit\Fakes;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ProjectView;
use RuntimeException;

final class FakeGetProject implements GetProject
{
    /** @var array<string, ProjectView> */
    private array $projects = [];

    public function withProject(ProjectView $project): void
    {
        $this->projects[$project->id] = $project;
    }

    public function get(GetProjectCommand $command): ProjectView
    {
        return $this->projects[$command->projectId]
            ?? throw new RuntimeException("Project not found: {$command->projectId}");
    }
}
