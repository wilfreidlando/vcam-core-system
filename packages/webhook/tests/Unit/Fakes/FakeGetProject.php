<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Unit\Fakes;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\ProjectView;
use Core\Support\Access\AccessScope;
use RuntimeException;

/**
 * Every project is `active` unless a status is set for it.
 */
final class FakeGetProject implements GetProject
{
    /** @var array<string, string> project id => status */
    private array $statuses = [];

    public function withStatus(string $projectId, string $status): self
    {
        $this->statuses[$projectId] = $status;

        return $this;
    }

    public function get(GetProjectCommand $command, AccessScope $scope): ProjectView
    {
        if (! $scope->allowsProject($command->projectId)) {
            throw new RuntimeException("Project not found: {$command->projectId}");
        }

        return new ProjectView($command->projectId, 'org-1', 'Project '.$command->projectId, $this->statuses[$command->projectId] ?? 'active');
    }
}
