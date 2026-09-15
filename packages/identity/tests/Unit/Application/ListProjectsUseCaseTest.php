<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\ListProjectsUseCase;
use Core\Identity\Contracts\ListProjectsCommand;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;

it('lists every project when no organization filter is given', function (): void {
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-a', 'Project One'));
    $projects->save(Project::create('project-2', 'org-b', 'Project Two'));

    $useCase = new ListProjectsUseCase($projects);
    $page = $useCase->list(new ListProjectsCommand);

    expect($page->total)->toBe(2);
});

it('filters by organization when given', function (): void {
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('project-1', 'org-a', 'Project One'));
    $projects->save(Project::create('project-2', 'org-b', 'Project Two'));

    $useCase = new ListProjectsUseCase($projects);
    $page = $useCase->list(new ListProjectsCommand(organizationId: 'org-a'));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('project-1')
        ->and($page->items[0]->name)->toBe('Project One');
});

it('paginates', function (): void {
    $projects = new InMemoryProjectRepository;
    for ($i = 0; $i < 3; $i++) {
        $projects->save(Project::create('project-'.$i, 'org-a', 'Project '.$i));
    }

    $useCase = new ListProjectsUseCase($projects);
    $page = $useCase->list(new ListProjectsCommand(page: 1, perPage: 2));

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(2);
});
