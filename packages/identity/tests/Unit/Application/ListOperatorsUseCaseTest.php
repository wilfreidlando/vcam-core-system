<?php

declare(strict_types=1);

use Core\Identity\Application\UseCase\ListOperatorsUseCase;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\InMemoryOperatorRepository;
use Core\Identity\Tests\Unit\Fakes\InMemoryOrganizationMembershipRepository;
use Core\Support\Access\AccessScope;

function operatorForListing(string $id, ?OperatorRole $role = OperatorRole::PlatformAdmin): Operator
{
    return new Operator(
        id: $id,
        email: $id.'@example.test',
        name: 'Operator '.$id,
        passwordHash: 'hash',
        role: $role,
        mfaSecret: null,
        mfaEnabled: false,
        mfaLastVerifiedTimestep: null,
        revokedAt: null,
        createdAt: new DateTimeImmutable('2026-09-22T10:00:00Z'),
    );
}

function membershipForListing(string $id, string $organizationId, string $operatorId, bool $revoked = false): OrganizationMembership
{
    $membership = new OrganizationMembership(
        id: $id,
        organizationId: $organizationId,
        operatorId: $operatorId,
        role: OrganizationRole::Viewer,
        createdAt: new DateTimeImmutable('2026-09-22T10:00:00Z'),
    );

    if ($revoked) {
        $membership->revoke(new DateTimeImmutable('2026-09-23T10:00:00Z'));
    }

    return $membership;
}

it('lists every operator for a platform scope', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operators->create(operatorForListing('op-1'));
    $operators->create(operatorForListing('op-2', null));

    $useCase = new ListOperatorsUseCase($operators, new InMemoryOrganizationMembershipRepository);

    $page = $useCase->list(new ListOperatorsCommand, AccessScope::platform());

    expect($page->total)->toBe(2)
        ->and($page->items)->toHaveCount(2);
});

it('lists only the operators of the account own organizations', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operators->create(operatorForListing('op-1', null));
    $operators->create(operatorForListing('op-2', null));
    $operators->create(operatorForListing('op-3', null));

    $memberships = new InMemoryOrganizationMembershipRepository;
    $memberships->save(membershipForListing('m-1', 'org-a', 'op-1'));
    $memberships->save(membershipForListing('m-2', 'org-a', 'op-2'));
    $memberships->save(membershipForListing('m-3', 'org-b', 'op-3'));

    $useCase = new ListOperatorsUseCase($operators, $memberships);

    $page = $useCase->list(new ListOperatorsCommand, new AccessScope(
        allOrganizations: false,
        organizationIds: ['org-a'],
        permissionsByOrganization: ['org-a' => ['projects:read']],
        allProjects: false,
        projectIds: [],
    ));

    expect($page->total)->toBe(2)
        ->and(array_map(static fn ($item): string => $item->id, $page->items))
        ->toBe(['op-1', 'op-2']);
});

it('never lists an operator whose membership is revoked', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operators->create(operatorForListing('op-1', null));
    $operators->create(operatorForListing('op-2', null));

    $memberships = new InMemoryOrganizationMembershipRepository;
    $memberships->save(membershipForListing('m-1', 'org-a', 'op-1'));
    $memberships->save(membershipForListing('m-2', 'org-a', 'op-2', revoked: true));

    $useCase = new ListOperatorsUseCase($operators, $memberships);

    $page = $useCase->list(new ListOperatorsCommand, new AccessScope(
        allOrganizations: false,
        organizationIds: ['org-a'],
        permissionsByOrganization: ['org-a' => ['projects:read']],
        allProjects: false,
        projectIds: [],
    ));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->id)->toBe('op-1');
});

it('lists nothing for an account that belongs to no organization', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operators->create(operatorForListing('op-1', null));

    $useCase = new ListOperatorsUseCase($operators, new InMemoryOrganizationMembershipRepository);

    $page = $useCase->list(new ListOperatorsCommand, AccessScope::none());

    expect($page->total)->toBe(0)
        ->and($page->items)->toBe([]);
});

it('scopes the paginated total, not only the rows', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operators->create(operatorForListing('op-1', null));
    $operators->create(operatorForListing('op-2', null));
    $operators->create(operatorForListing('op-3', null));

    $memberships = new InMemoryOrganizationMembershipRepository;
    $memberships->save(membershipForListing('m-1', 'org-a', 'op-1'));

    $useCase = new ListOperatorsUseCase($operators, $memberships);

    $page = $useCase->list(new ListOperatorsCommand(page: 1, perPage: 10), new AccessScope(
        allOrganizations: false,
        organizationIds: ['org-a'],
        permissionsByOrganization: ['org-a' => ['projects:read']],
        allProjects: false,
        projectIds: [],
    ));

    expect($page->total)->toBe(1)
        ->and($page->items)->toHaveCount(1);
});

it('reports a null role for an account without a platform role', function (): void {
    $operators = new InMemoryOperatorRepository;
    $operators->create(operatorForListing('op-1', null));

    $useCase = new ListOperatorsUseCase($operators, new InMemoryOrganizationMembershipRepository);

    $page = $useCase->list(new ListOperatorsCommand, AccessScope::platform());

    expect($page->items[0]->role)->toBeNull();
});
