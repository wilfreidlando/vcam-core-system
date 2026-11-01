<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\ResolveAccessScopeUseCase;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Domain\Model\Project;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationMembershipRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryProjectRepository;

/**
 * ADR-0069 §1/§2 — the scope of a console account: a platform role sees
 * everything, an account without one sees its organizations, their
 * projects, and the permissions of its role in each.
 */
function scopedOperator(string $id, ?OperatorRole $role): Operator
{
    return new Operator(
        id: $id,
        email: $id.'@example.test',
        name: 'Account '.$id,
        passwordHash: 'hash',
        role: $role,
        mfaSecret: null,
        mfaEnabled: true,
        mfaLastVerifiedTimestep: null,
        revokedAt: null,
        createdAt: new DateTimeImmutable('2026-10-24T10:00:00Z'),
    );
}

/**
 * @return array{ResolveAccessScopeUseCase, InMemoryOperatorRepository, InMemoryOrganizationMembershipRepository}
 */
function scopeResolver(): array
{
    $operators = new InMemoryOperatorRepository;
    $memberships = new InMemoryOrganizationMembershipRepository;
    $projects = new InMemoryProjectRepository;
    $projects->save(Project::create('p-a1', 'org-a', 'A1'));
    $projects->save(Project::create('p-a2', 'org-a', 'A2'));
    $projects->save(Project::create('p-b1', 'org-b', 'B1'));
    $projects->save(Project::create('p-c1', 'org-c', 'C1'));

    return [new ResolveAccessScopeUseCase($operators, $memberships, $projects), $operators, $memberships];
}

it('gives any platform role the whole platform', function (OperatorRole $role): void {
    [$resolver, $operators] = scopeResolver();
    $operators->create(scopedOperator('op-1', $role));

    $scope = $resolver->forOperator('op-1');

    expect($scope->allOrganizations)->toBeTrue()
        ->and($scope->allProjects)->toBeTrue();
})->with(OperatorRole::cases());

it('restricts an account without platform role to its organizations, their projects and its role there', function (): void {
    [$resolver, $operators, $memberships] = scopeResolver();
    $operators->create(scopedOperator('op-1', null));
    $memberships->save(new OrganizationMembership('m-1', 'org-a', 'op-1', OrganizationRole::Finance, new DateTimeImmutable('2026-10-24T10:00:00Z')));
    $memberships->save(new OrganizationMembership('m-2', 'org-b', 'op-1', OrganizationRole::Viewer, new DateTimeImmutable('2026-10-24T10:00:00Z')));

    $scope = $resolver->forOperator('op-1');

    expect($scope->allOrganizations)->toBeFalse()
        ->and($scope->organizationIds)->toBe(['org-a', 'org-b'])
        ->and($scope->projectIds)->toBe(['p-a1', 'p-a2', 'p-b1'])
        ->and($scope->projectIdsByOrganization)->toBe(['org-a' => ['p-a1', 'p-a2'], 'org-b' => ['p-b1']])
        ->and($scope->allowsProject('p-c1'))->toBeFalse()
        ->and($scope->hasPermission('org-a', 'money-exceptions:write'))->toBeTrue()
        ->and($scope->hasPermission('org-b', 'money-exceptions:write'))->toBeFalse()
        // The permission narrows the scope to the organization holding it.
        ->and($scope->restrictedTo('money-exceptions:write')->projectIds)->toBe(['p-a1', 'p-a2']);
});

it('ignores a revoked membership', function (): void {
    [$resolver, $operators, $memberships] = scopeResolver();
    $operators->create(scopedOperator('op-1', null));
    $membership = new OrganizationMembership('m-1', 'org-a', 'op-1', OrganizationRole::Owner, new DateTimeImmutable('2026-10-24T10:00:00Z'));
    $membership->revoke(new DateTimeImmutable('2026-10-24T11:00:00Z'));
    $memberships->save($membership);

    expect($resolver->forOperator('op-1')->isEmpty())->toBeTrue();
});

it('sees nothing for an unknown account, never everything', function (): void {
    [$resolver] = scopeResolver();

    expect($resolver->forOperator('ghost')->isEmpty())->toBeTrue();
});
