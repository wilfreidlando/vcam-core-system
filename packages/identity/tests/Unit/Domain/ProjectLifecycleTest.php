<?php

declare(strict_types=1);

use Core\Identity\Domain\Exception\InvalidProjectStatusTransition;
use Core\Identity\Domain\Exception\ProjectStatusChangeRequiresReason;
use Core\Identity\Domain\Model\Project;
use Core\Identity\Domain\Model\ProjectStatus;

it('suspends an active project and records when and why', function (): void {
    $project = Project::create('project-1', 'org-1', 'Test Project');
    $at = new DateTimeImmutable('2026-10-24T10:00:00Z');

    $suspended = $project->changeStatus(ProjectStatus::Suspended, $at, 'Facturation en cours de vérification');

    expect($suspended->isSuspended())->toBeTrue()
        ->and($suspended->isActive())->toBeFalse()
        ->and($suspended->statusChangedAt?->format(DATE_ATOM))->toBe('2026-10-24T10:00:00+00:00')
        ->and($suspended->statusReason)->toBe('Facturation en cours de vérification');
});

it('reactivates a suspended project', function (): void {
    $project = Project::create('project-1', 'org-1', 'Test Project');
    $suspended = $project->changeStatus(ProjectStatus::Suspended, new DateTimeImmutable, 'Raison');

    $reactivated = $suspended->changeStatus(ProjectStatus::Active, new DateTimeImmutable, 'Vérification terminée');

    expect($reactivated->isActive())->toBeTrue();
});

it('archives a suspended project, and archiving is final', function (): void {
    $project = Project::create('project-1', 'org-1', 'Test Project');
    $suspended = $project->changeStatus(ProjectStatus::Suspended, new DateTimeImmutable, 'Raison');

    $archived = $suspended->changeStatus(ProjectStatus::Archived, new DateTimeImmutable, 'Fin de la relation');

    expect($archived->isArchived())->toBeTrue();

    // Archived is final: nothing comes out of it.
    $archived->changeStatus(ProjectStatus::Active, new DateTimeImmutable, 'On change d avis');
})->throws(InvalidProjectStatusTransition::class);

it('archives an active project directly (ADR-0069 §3: active|suspended → archived)', function (): void {
    $archived = Project::create('project-1', 'org-1', 'Test Project')
        ->changeStatus(ProjectStatus::Archived, new DateTimeImmutable, 'Fin de la relation');

    expect($archived->isArchived())->toBeTrue();
});

it('refuses a status change without a reason', function (): void {
    Project::create('project-1', 'org-1', 'Test Project')
        ->changeStatus(ProjectStatus::Suspended, new DateTimeImmutable, '   ');
})->throws(ProjectStatusChangeRequiresReason::class);

it('knows which transitions are allowed', function (): void {
    expect(ProjectStatus::Active->allowedTransitions())->toBe([ProjectStatus::Suspended, ProjectStatus::Archived])
        ->and(ProjectStatus::Suspended->allowedTransitions())->toBe([ProjectStatus::Active, ProjectStatus::Archived])
        ->and(ProjectStatus::Archived->allowedTransitions())->toBe([])
        ->and(ProjectStatus::Active->canTransitionTo(ProjectStatus::Archived))->toBeTrue()
        ->and(ProjectStatus::Archived->canTransitionTo(ProjectStatus::Active))->toBeFalse()
        ->and(ProjectStatus::Suspended->canTransitionTo(ProjectStatus::Archived))->toBeTrue();
});
