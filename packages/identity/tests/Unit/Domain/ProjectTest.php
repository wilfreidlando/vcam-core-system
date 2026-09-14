<?php

declare(strict_types=1);

use Core\Identity\Domain\Model\Project;

it('is active when created', function (): void {
    $project = Project::create('project-1', 'org-1', 'Tourism Platform');

    expect($project->isActive())->toBeTrue()
        ->and($project->organizationId)->toBe('org-1')
        ->and($project->name())->toBe('Tourism Platform');
});

it('rejects a blank name', function (): void {
    Project::create('project-1', 'org-1', '');
})->throws(InvalidArgumentException::class);
