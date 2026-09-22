<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\UpdateProject;
use Core\Identity\Contracts\UpdateProjectCommand;
use Core\Identity\Domain\Exception\ProjectNotFound;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Tests\Unit\Fakes\FixedClock;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;
use Core\Identity\Tests\Unit\Fakes\RecordingAuditLogger;

it('renames and changes the status of an existing project, without touching its organization, and audits before/after', function (): void {
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-1', 'Tourism Platform'));

    $audit = new RecordingAuditLogger;
    $useCase = new UpdateProject($projects, $audit, new FixedClock);

    $useCase->update(new UpdateProjectCommand('project-1', 'Tourism Platform Renamed', 'suspended'), actor: 'operator:1', correlationId: 'corr-1');

    $updated = $projects->find('project-1');
    assert($updated instanceof Project);
    expect($updated->name())->toBe('Tourism Platform Renamed')
        ->and($updated->status())->toBe('suspended')
        ->and($updated->organizationId)->toBe('org-1')
        ->and($audit->entries)->toHaveCount(1)
        ->and($audit->entries[0]->action)->toBe('project.updated')
        ->and($audit->entries[0]->before)->toBe(['name' => 'Tourism Platform', 'status' => 'active'])
        ->and($audit->entries[0]->after)->toBe(['name' => 'Tourism Platform Renamed', 'status' => 'suspended']);
});

it('throws when the project does not exist', function (): void {
    $useCase = new UpdateProject(new InMemoryProjectRepository, new RecordingAuditLogger, new FixedClock);

    $useCase->update(new UpdateProjectCommand('missing', 'New Name', 'active'), actor: 'operator:1', correlationId: null);
})->throws(ProjectNotFound::class);
