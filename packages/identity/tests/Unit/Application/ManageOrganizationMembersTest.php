<?php

declare(strict_types=1);

use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InvalidOrganizationRole;
use Core\Identity\Contracts\LastOrganizationOwner;
use Core\Identity\Contracts\ListOrganizationMembersCommand;
use Core\Identity\Contracts\OrganizationInvitationAlreadyExists;
use Core\Identity\Contracts\OrganizationMemberManagementForbidden;
use Core\Identity\Contracts\OrganizationMemberNotFound;
use Core\Identity\Contracts\OrganizationMemberView;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Contracts\OrganizationPrivilegeEscalation;
use Core\Identity\Contracts\OrganizationRefusal;
use Core\Identity\Contracts\PlatformRoleAssignmentForbidden;
use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\OperatorRole;
use Core\Identity\Tests\Unit\Fakes\OrganizationMembersWorld;

/*
 * ADR-0069 §1/§4 and "Sécurité" — who may invite, revoke and change the
 * role of the members of an organization.
 */

// --- Invitation ----------------------------------------------------------

it('lets an owner invite: the clear token is returned once and only its hash is stored', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    $created = $world->inviteAs($owner, $org, '  New@Acme.TEST ', 'org_viewer');
    $stored = $world->invitations->find($created->id);

    expect($created->token)->toBeString()
        ->and($created->email)->toBe('new@acme.test')
        ->and($created->role)->toBe('org_viewer')
        ->and($created->expiresAt)->toEqual($world->clock->now()->modify('+72 hours'))
        ->and($stored)->not->toBeNull()
        ->and($stored?->token->hash())->toBe(InvitationToken::hashOf((string) $created->token))
        ->and($stored?->token->revealPlainText())->toBeNull();
});

it('audits the invitation with the organization id, never with the token', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    $created = $world->inviteAs($owner, $org, 'new@acme.test', 'org_viewer');
    $entry = $world->audit->entries[0];

    expect($world->audit->entries)->toHaveCount(1)
        ->and($entry->action)->toBe('organization.member_invited')
        ->and($entry->organizationId)->toBe($org->id);

    $journal = (string) json_encode([$entry->before, $entry->after, $entry->context]);
    expect(str_contains($journal, (string) $created->token))->toBeFalse()
        ->and(str_contains($journal, InvitationToken::hashOf((string) $created->token)))->toBeFalse();
});

it('refuses a second invitation of the same email while the first is pending', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $world->inviteAs($owner, $org, 'new@acme.test', 'org_viewer');

    expect(fn () => $world->inviteAs($owner, $org, 'NEW@acme.test', 'org_admin'))
        ->toThrow(OrganizationInvitationAlreadyExists::class);
});

it('allows a new invitation once the previous one has expired', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $world->inviteAs($owner, $org, 'new@acme.test', 'org_viewer');
    $world->clock->advance('+73 hours');

    expect($world->inviteAs($owner, $org, 'new@acme.test', 'org_viewer')->token)->toBeString();
});

it('refuses to invite an account that is already a member', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $world->member($org, $world->account('member@acme.test'), OrganizationRole::Viewer);

    expect(fn () => $world->inviteAs($owner, $org, 'member@acme.test', 'org_admin'))
        ->toThrow(AlreadyOrganizationMember::class);
});

it('lets an org_admin invite below the owner role', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $admin = $world->account('admin@acme.test');
    $world->member($org, $admin, OrganizationRole::Admin);

    expect($world->inviteAs($admin, $org, 'new@acme.test', 'org_admin')->role)->toBe('org_admin');
});

it('refuses an org_admin granting org_owner through an invitation (403)', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $admin = $world->account('admin@acme.test');
    $world->member($org, $admin, OrganizationRole::Admin);

    $refusal = OrganizationMembersWorld::refusal(OrganizationPrivilegeEscalation::class, fn () => $world->inviteAs($admin, $org, 'new@acme.test', 'org_owner'));
    expect($refusal->httpStatus())->toBe(403);
    expect($world->invitations->all())->toBe([]);
});

it('refuses members without members:write (403)', function (string $role): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $member = $world->account('m@acme.test');
    $world->member($org, $member, OrganizationRole::from($role));

    $refusal = OrganizationMembersWorld::refusal(OrganizationMemberManagementForbidden::class, fn () => $world->inviteAs($member, $org, 'new@acme.test', 'org_viewer'));
    expect($refusal->httpStatus())->toBe(403);
})->with(['org_finance', 'org_support', 'org_viewer']);

it('refuses a platform role being granted through a membership, whoever asks', function (string $platformRole): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $platform = $world->account('root@platform.test', OperatorRole::PlatformAdmin);

    $refusal = OrganizationMembersWorld::refusal(PlatformRoleAssignmentForbidden::class, fn () => $world->inviteAs($platform, $org, 'new@acme.test', $platformRole));
    expect($refusal->httpStatus())->toBe(403)
        ->and($refusal->errorCode())->toBe('platform-role-forbidden');
})->with(array_column(OperatorRole::cases(), 'value'));

it('refuses an unknown role (422)', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    expect(fn () => $world->inviteAs($owner, $org, 'new@acme.test', 'superuser'))
        ->toThrow(InvalidOrganizationRole::class);
});

it('lets a platform_admin invite the first owner of an organization', function (): void {
    [$world] = OrganizationMembersWorld::seeded();
    $platform = $world->account('root@platform.test', OperatorRole::PlatformAdmin);
    $fresh = $world->organization('Fresh');

    expect($world->inviteAs($platform, $fresh, 'first@fresh.test', 'org_owner')->role)->toBe('org_owner');
});

it('refuses a platform role without members:write', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $auditor = $world->account('auditor@platform.test', OperatorRole::ReadOnlyAuditor);

    expect(fn () => $world->inviteAs($auditor, $org, 'new@acme.test', 'org_owner'))
        ->toThrow(OrganizationMemberManagementForbidden::class);
});

it('answers 404 for an organization outside the scope', function (): void {
    [$world, , $owner] = OrganizationMembersWorld::seeded();
    $other = $world->organization('Other');

    $refusal = OrganizationMembersWorld::refusal(OrganizationNotVisible::class, fn () => $world->inviteAs($owner, $other, 'new@acme.test', 'org_viewer'));
    expect($refusal->httpStatus())->toBe(404);
    expect($world->invitations->all())->toBe([]);
});

// --- Revocation ----------------------------------------------------------

it('lets an owner revoke a member under the owner lock, and audits it with the organization id', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $member = $world->account('member@acme.test');
    $world->member($org, $member, OrganizationRole::Viewer);

    $world->revokeAs($owner, $org, $member);

    expect($world->memberships->findActive($org->id, $member->id))->toBeNull()
        ->and($world->audit->entries[0]->action)->toBe('organization.member_revoked')
        ->and($world->audit->entries[0]->organizationId)->toBe($org->id)
        ->and($world->memberships->ownerLocks)->toBe(1);
});

it('refuses to revoke the last active owner (409 last-owner) — itself included', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    $refusal = OrganizationMembersWorld::refusal(LastOrganizationOwner::class, fn () => $world->revokeAs($owner, $org, $owner));
    expect($refusal->httpStatus())->toBe(409)
        ->and($refusal->errorCode())->toBe('last-owner');
    expect($world->memberships->findActive($org->id, $owner->id))->not->toBeNull();
});

it('refuses a platform_admin revoking the last owner too', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $platform = $world->account('root@platform.test', OperatorRole::PlatformAdmin);

    expect(fn () => $world->revokeAs($platform, $org, $owner))->toThrow(LastOrganizationOwner::class);
});

it('lets an owner be revoked while another owner remains', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $second = $world->account('second@acme.test');
    $world->member($org, $second, OrganizationRole::Owner);

    $world->revokeAs($second, $org, $owner);

    expect($world->memberships->findActive($org->id, $owner->id))->toBeNull();
});

it('never leaves zero owners when two owners revoke each other', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $second = $world->account('second@acme.test');
    $world->member($org, $second, OrganizationRole::Owner);

    // Both requests resolved their scope before either ran.
    $secondsStaleScope = $world->scopeOf($second);

    $world->revokeAs($owner, $org, $second);

    // The second request runs after the first committed (what the owner
    // row lock enforces under concurrency): the use case re-reads its
    // actor's membership under the lock — no longer an owner, refused —
    // rather than trusting the scope resolved before.
    expect(fn () => $world->revokeAs($second, $org, $owner, $secondsStaleScope))
        ->toThrow(OrganizationMemberManagementForbidden::class);
    expect($world->memberships->lockActiveOwners($org->id))->toBe(1);
});

it('refuses an org_admin revoking an owner (403 privilege-escalation)', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $second = $world->account('second@acme.test');
    $world->member($org, $second, OrganizationRole::Owner);
    $admin = $world->account('admin@acme.test');
    $world->member($org, $admin, OrganizationRole::Admin);

    expect(fn () => $world->revokeAs($admin, $org, $second))->toThrow(OrganizationPrivilegeEscalation::class);
});

it('lets an org_admin revoke a member below the owner role', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $admin = $world->account('admin@acme.test');
    $world->member($org, $admin, OrganizationRole::Admin);
    $viewer = $world->account('viewer@acme.test');
    $world->member($org, $viewer, OrganizationRole::Viewer);

    $world->revokeAs($admin, $org, $viewer);

    expect($world->memberships->findActive($org->id, $viewer->id))->toBeNull();
});

it('refuses a member without members:write before saying whether the target exists', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $viewer = $world->account('viewer@acme.test');
    $world->member($org, $viewer, OrganizationRole::Viewer);
    $ghost = $world->account('ghost@elsewhere.test');

    expect(fn () => $world->revokeAs($viewer, $org, $ghost))->toThrow(OrganizationMemberManagementForbidden::class);
});

it('answers 404 for a member that is not in the organization', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $ghost = $world->account('ghost@elsewhere.test');

    expect(fn () => $world->revokeAs($owner, $org, $ghost))->toThrow(OrganizationMemberNotFound::class);
});

it('answers 404 on another organization’s members', function (): void {
    [$world, , $owner] = OrganizationMembersWorld::seeded();
    $other = $world->organization('Other');
    $otherOwner = $world->account('owner@other.test');
    $world->member($other, $otherOwner, OrganizationRole::Owner);

    expect(fn () => $world->revokeAs($owner, $other, $otherOwner))->toThrow(OrganizationNotVisible::class);
    expect($world->memberships->findActive($other->id, $otherOwner->id))->not->toBeNull();
});

// --- Role change ---------------------------------------------------------

it('lets an owner change a role, and audits before/after with the organization id', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $member = $world->account('member@acme.test');
    $world->member($org, $member, OrganizationRole::Viewer);

    $world->changeRoleAs($owner, $org, $member, 'org_finance');
    $entry = $world->audit->entries[0];

    expect($world->memberships->findActive($org->id, $member->id)?->role)->toBe(OrganizationRole::Finance)
        ->and($entry->action)->toBe('organization.member_role_changed')
        ->and($entry->before)->toBe(['operator_id' => $member->id, 'role' => 'org_viewer'])
        ->and($entry->after)->toBe(['operator_id' => $member->id, 'role' => 'org_finance'])
        ->and($entry->organizationId)->toBe($org->id);
});

it('refuses an org_admin promoting itself to org_owner', function (): void {
    [$world, $org] = OrganizationMembersWorld::seeded();
    $admin = $world->account('admin@acme.test');
    $world->member($org, $admin, OrganizationRole::Admin);

    expect(fn () => $world->changeRoleAs($admin, $org, $admin, 'org_owner'))->toThrow(OrganizationPrivilegeEscalation::class);
    expect($world->memberships->findActive($org->id, $admin->id)?->role)->toBe(OrganizationRole::Admin)
        ->and($world->audit->entries)->toBe([]);
});

it('refuses an org_admin demoting an owner', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $admin = $world->account('admin@acme.test');
    $world->member($org, $admin, OrganizationRole::Admin);

    expect(fn () => $world->changeRoleAs($admin, $org, $owner, 'org_viewer'))->toThrow(OrganizationPrivilegeEscalation::class);
});

it('refuses an owner granting a platform role through a role change', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $member = $world->account('member@acme.test');
    $world->member($org, $member, OrganizationRole::Viewer);

    expect(fn () => $world->changeRoleAs($owner, $org, $member, 'platform_admin'))->toThrow(PlatformRoleAssignmentForbidden::class);
});

it('refuses demoting the last owner (409 last-owner)', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    expect(fn () => $world->changeRoleAs($owner, $org, $owner, 'org_admin'))->toThrow(LastOrganizationOwner::class);
});

it('lets an owner be demoted once another owner exists', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $member = $world->account('member@acme.test');
    $world->member($org, $member, OrganizationRole::Viewer);

    $world->changeRoleAs($owner, $org, $member, 'org_owner');
    $world->changeRoleAs($member, $org, $owner, 'org_admin');

    expect($world->memberships->findActive($org->id, $owner->id)?->role)->toBe(OrganizationRole::Admin);
});

it('publishes every refusal with a stable code and status', function (OrganizationRefusal $refusal, string $code, int $status): void {
    expect($refusal->errorCode())->toBe($code)
        ->and($refusal->httpStatus())->toBe($status);
})->with([
    'last owner' => [LastOrganizationOwner::forOrganization('o'), 'last-owner', 409],
    'escalation' => [OrganizationPrivilegeEscalation::forRole('o', 'org_owner'), 'privilege-escalation', 403],
    'escalation on owner' => [OrganizationPrivilegeEscalation::onOwnerMembership('o'), 'privilege-escalation', 403],
    'platform role' => [PlatformRoleAssignmentForbidden::forRole('o', 'platform_admin'), 'platform-role-forbidden', 403],
    'forbidden' => [OrganizationMemberManagementForbidden::forOrganization('o'), 'organization-members-forbidden', 403],
    'not visible' => [OrganizationNotVisible::withId('o'), 'organization-not-found', 404],
    'member not found' => [OrganizationMemberNotFound::forMember('o', 'x'), 'organization-member-not-found', 404],
    'already member' => [AlreadyOrganizationMember::forEmail('o', 'a@b.c'), 'already-member', 409],
]);

// --- Listing -------------------------------------------------------------

it('lists the active members of a visible organization only', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $world->member($org, $world->account('member@acme.test'), OrganizationRole::Viewer);
    $other = $world->organization('Other');

    $members = $world->list()->list(new ListOrganizationMembersCommand($org->id), $world->scopeOf($owner));

    expect($members->total)->toBe(2)
        ->and(array_map(static fn (OrganizationMemberView $m): string => $m->email, $members->items))
        ->toContain('member@acme.test', 'owner@acme.test');

    expect(fn () => $world->list()->list(new ListOrganizationMembersCommand($other->id), $world->scopeOf($owner)))
        ->toThrow(OrganizationNotVisible::class);
});
