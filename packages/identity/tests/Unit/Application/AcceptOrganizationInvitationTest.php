<?php

declare(strict_types=1);

use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InvitationAccountPasswordInvalid;
use Core\Identity\Contracts\InvitationNotUsable;
use Core\Identity\Contracts\InvitationPasswordTooWeak;
use Core\Identity\Contracts\OperatorAlreadyExists;
use Core\Identity\Contracts\OrganizationInvitationNotFound;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\Tests\Unit\Fakes\OrganizationMembersWorld;

/*
 * ADR-0069 §4 / ADR-0081 S-07 — the activation of an invitation.
 */

it('creates the account with the chosen password and no platform role, the membership, and consumes the invitation', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    $accepted = $world->acceptToken($world->issueToken($owner, $org));

    $operator = $world->operators->find($accepted->operatorId);

    expect($accepted->accountCreated)->toBeTrue()
        ->and($operator?->email)->toBe('invitee@acme.test')
        ->and($operator?->role)->toBeNull()
        ->and($operator?->verifyPassword('a chosen passphrase'))->toBeTrue()
        ->and($operator?->mfaEnabled())->toBeFalse()
        ->and($world->memberships->findActive($org->id, $accepted->operatorId)?->role)->toBe(OrganizationRole::Admin)
        ->and($world->onlyInvitation()->isAccepted())->toBeTrue()
        ->and($world->invitations->locks)->toBe(1);
});

it('hands over to the existing MFA enrollment flow (the login challenge, purpose mfa_enrollment)', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();

    $accepted = $world->acceptToken($world->issueToken($owner, $org));

    expect($accepted->challengePurpose)->toBe('mfa_enrollment')
        ->and($world->challenges->verify($accepted->challengeToken, 'mfa_enrollment'))->toBe($accepted->operatorId)
        // Not a session, not a confirmation: the usual `mfa/enroll` step.
        ->and($world->challenges->verify($accepted->challengeToken, 'mfa_confirm'))->toBeNull();
});

it('audits the acceptance with the organization id, without the token or the password', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $token = $world->issueToken($owner, $org);

    $accepted = $world->acceptToken($token);
    $entry = $world->audit->entries[1];

    expect($entry->action)->toBe('organization.invitation_accepted')
        ->and($entry->organizationId)->toBe($org->id)
        ->and($entry->actor)->toBe('operator:'.$accepted->operatorId);

    $journal = (string) json_encode([$entry->before, $entry->after, $entry->context]);
    expect(str_contains($journal, $token))->toBeFalse()
        ->and(str_contains($journal, 'a chosen passphrase'))->toBeFalse();
});

it('is single use: a second acceptance is refused', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $token = $world->issueToken($owner, $org);
    $world->acceptToken($token);

    $refusal = OrganizationMembersWorld::refusal(InvitationNotUsable::class, fn () => $world->acceptToken($token, 'another passphrase!'));
    expect($refusal->reason)->toBe('already_used')
        ->and($refusal->httpStatus())->toBe(409);
});

it('refuses an expired invitation (72 h)', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $token = $world->issueToken($owner, $org);
    $world->clock->advance('+72 hours');

    $refusal = OrganizationMembersWorld::refusal(InvitationNotUsable::class, fn () => $world->acceptToken($token));
    expect($refusal->reason)->toBe('expired');
    expect($world->operators->findByEmail('invitee@acme.test'))->toBeNull();
});

it('refuses a revoked invitation', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $token = $world->issueToken($owner, $org);
    $invitation = $world->onlyInvitation();
    $invitation->revoke($world->clock->now());
    $world->invitations->save($invitation);

    $refusal = OrganizationMembersWorld::refusal(InvitationNotUsable::class, fn () => $world->acceptToken($token));
    expect($refusal->reason)->toBe('revoked');
});

it('answers 404 for an unknown token', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $world->issueToken($owner, $org);

    expect(fn () => $world->acceptToken(str_repeat('A', 43)))->toThrow(OrganizationInvitationNotFound::class);
});

it('refuses a password shorter than 12 characters for a new account, without consuming the token', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $token = $world->issueToken($owner, $org);

    expect(fn () => $world->acceptToken($token, 'short'))->toThrow(InvitationPasswordTooWeak::class);
    expect($world->onlyInvitation()->isAccepted())->toBeFalse();
});

it('lets an existing account join with its CURRENT password, which is never replaced', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $existing = $world->account('invitee@acme.test', password: 'the existing secret', mfaEnabled: true);
    $token = $world->issueToken($owner, $org, 'invitee@acme.test', 'org_viewer');

    $accepted = $world->acceptToken($token, 'the existing secret');

    expect($accepted->accountCreated)->toBeFalse()
        ->and($accepted->operatorId)->toBe($existing->id)
        ->and($world->operators->find($existing->id)?->verifyPassword('the existing secret'))->toBeTrue()
        ->and($world->memberships->findActive($org->id, $existing->id)?->role)->toBe(OrganizationRole::Viewer)
        // MFA already enrolled: the usual verify step, not a new enrollment.
        ->and($accepted->challengePurpose)->toBe('mfa');
});

it('refuses an existing account with a password that is not its own — the token alone never takes an account over', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $existing = $world->account('invitee@acme.test', password: 'the existing secret');
    $token = $world->issueToken($owner, $org);

    expect(fn () => $world->acceptToken($token, 'attacker picked this'))->toThrow(InvitationAccountPasswordInvalid::class);

    expect($world->operators->find($existing->id)?->verifyPassword('the existing secret'))->toBeTrue()
        ->and($world->operators->find($existing->id)?->verifyPassword('attacker picked this'))->toBeFalse()
        ->and($world->memberships->findActive($org->id, $existing->id))->toBeNull()
        ->and($world->onlyInvitation()->isAccepted())->toBeFalse();
});

it('refuses a revoked existing account', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $world->account('invitee@acme.test', password: 'the existing secret', revoked: true);
    $token = $world->issueToken($owner, $org);

    expect(fn () => $world->acceptToken($token, 'the existing secret'))->toThrow(OperatorAlreadyExists::class);
});

it('refuses an invitation for an account that became a member in the meantime', function (): void {
    [$world, $org, $owner] = OrganizationMembersWorld::seeded();
    $existing = $world->account('invitee@acme.test', password: 'the existing secret');
    $token = $world->issueToken($owner, $org);
    $world->member($org, $existing, OrganizationRole::Viewer);

    expect(fn () => $world->acceptToken($token, 'the existing secret'))->toThrow(AlreadyOrganizationMember::class);
});
