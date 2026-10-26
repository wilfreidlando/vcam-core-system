<?php

declare(strict_types=1);

use Core\Support\Access\AccessScope;

/**
 * ADR-0069 §2 — visibility is not permission. An account sees every
 * organization it belongs to, but a page gated by a permission must read
 * only the organizations where that permission is held.
 */
function twoOrganizationScope(): AccessScope
{
    return new AccessScope(
        allOrganizations: false,
        organizationIds: ['org-a', 'org-b'],
        permissionsByOrganization: [
            'org-a' => ['payments:read', 'projects:read'],
            'org-b' => ['projects:read', 'projects:write'],
        ],
        allProjects: false,
        projectIds: ['p-a1', 'p-a2', 'p-b1'],
        projectIdsByOrganization: [
            'org-a' => ['p-a1', 'p-a2'],
            'org-b' => ['p-b1'],
        ],
    );
}

it('narrows to the organizations holding the permission, and to their projects only', function (): void {
    $payments = twoOrganizationScope()->restrictedTo('payments:read');

    expect($payments->organizationIds)->toBe(['org-a'])
        ->and($payments->projectIds)->toBe(['p-a1', 'p-a2'])
        ->and($payments->allowsProject('p-b1'))->toBeFalse()
        ->and($payments->allowsOrganization('org-b'))->toBeFalse()
        ->and($payments->projectFilter())->toBe(['p-a1', 'p-a2']);
});

it('keeps every organization holding the permission', function (): void {
    $projects = twoOrganizationScope()->restrictedTo('projects:read');

    expect($projects->organizationIds)->toBe(['org-a', 'org-b'])
        ->and($projects->projectIds)->toBe(['p-a1', 'p-a2', 'p-b1']);
});

it('narrows to nothing when no organization holds the permission', function (): void {
    $none = twoOrganizationScope()->restrictedTo('operators:write');

    expect($none->isEmpty())->toBeTrue()
        ->and($none->projectFilter())->toBe([])
        ->and($none->organizationFilter())->toBe([]);
});

it('honours the owner wildcard', function (): void {
    $owner = new AccessScope(false, ['org-a'], ['org-a' => ['*']], false, ['p-a1'], ['org-a' => ['p-a1']]);

    expect($owner->restrictedTo('anything:write')->projectIds)->toBe(['p-a1']);
});

it('leaves a platform scope unchanged', function (): void {
    $platform = AccessScope::platform()->restrictedTo('payments:read');

    expect($platform->allOrganizations)->toBeTrue()
        ->and($platform->projectFilter())->toBeNull()
        ->and($platform->isEmpty())->toBeFalse();
});

it('narrows a scope without permissions — a project API key — to nothing', function (): void {
    expect(AccessScope::forProject('p-a1')->restrictedTo('payments:read')->isEmpty())->toBeTrue()
        ->and(AccessScope::forProject('p-a1')->isEmpty())->toBeFalse();
});

it('sees nothing when empty, and says so', function (): void {
    expect(AccessScope::none()->isEmpty())->toBeTrue()
        ->and(AccessScope::none()->projectFilter())->toBe([])
        ->and(AccessScope::none()->allowsProject('p-a1'))->toBeFalse();
});

it('records the projects of the organization it is built for', function (): void {
    $scope = AccessScope::forOrganization('org-a', ['p-a1']);

    expect($scope->projectIdsByOrganization)->toBe(['org-a' => ['p-a1']])
        ->and($scope->allowsProject('p-a1'))->toBeTrue();
});
