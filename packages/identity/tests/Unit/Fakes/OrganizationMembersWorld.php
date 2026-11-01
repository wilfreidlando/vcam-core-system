<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Application\Support\OrganizationMembershipPolicy;
use Core\Identity\Application\UseCase\AcceptOrganizationInvitation;
use Core\Identity\Application\UseCase\ChangeOrganizationMemberRole;
use Core\Identity\Application\UseCase\InviteOrganizationMember;
use Core\Identity\Application\UseCase\ListOrganizationMembers;
use Core\Identity\Application\UseCase\RevokeOrganizationMember;
use Core\Identity\Contracts\AcceptedOrganizationInvitation;
use Core\Identity\Contracts\AcceptOrganizationInvitationCommand;
use Core\Identity\Contracts\ChangeOrganizationMemberRoleCommand;
use Core\Identity\Contracts\CreatedOrganizationInvitation;
use Core\Identity\Contracts\InviteOrganizationMemberCommand;
use Core\Identity\Contracts\RevokeOrganizationMemberCommand;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Model\OrganizationInvitation;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Model\OrganizationRole;
use Core\Identity\OperatorRole;
use Core\Support\Access\AccessScope;
use DateTimeImmutable;
use LogicException;
use Throwable;

/**
 * ADR-0069 — every in-memory collaborator of the organization-membership
 * use cases, wired once, plus the few builders the tests need (an
 * organization, an account, a membership, the scope an account resolves
 * to).
 */
final class OrganizationMembersWorld
{
    public InMemoryOrganizationRepository $organizations;

    public InMemoryOperatorRepository $operators;

    public InMemoryOrganizationMembershipRepository $memberships;

    public InMemoryOrganizationInvitationRepository $invitations;

    public InMemoryLoginChallengeCodec $challenges;

    public RecordingAuditLogger $audit;

    public SequentialIdentifier $identifier;

    public MutableClock $clock;

    private int $sequence = 0;

    public function __construct()
    {
        $this->organizations = new InMemoryOrganizationRepository;
        $this->operators = new InMemoryOperatorRepository;
        $this->memberships = new InMemoryOrganizationMembershipRepository;
        $this->invitations = new InMemoryOrganizationInvitationRepository;
        $this->challenges = new InMemoryLoginChallengeCodec;
        $this->audit = new RecordingAuditLogger;
        $this->identifier = new SequentialIdentifier;
        $this->clock = new MutableClock(new DateTimeImmutable('2026-10-25T10:00:00Z'));
    }

    /**
     * A world with one organization owned by one account — the starting
     * point of most tests.
     *
     * @return array{0: self, 1: Organization, 2: Operator}
     */
    public static function seeded(): array
    {
        $world = new self;
        $organization = $world->organization();
        $owner = $world->account('owner@acme.test');
        $world->member($organization, $owner, OrganizationRole::Owner);

        return [$world, $organization, $owner];
    }

    public function inviteAs(Operator $actor, Organization $organization, string $email, string $role): CreatedOrganizationInvitation
    {
        return $this->invite()->invite(
            new InviteOrganizationMemberCommand($organization->id, $email, $role, $actor->id),
            $this->scopeOf($actor),
            actor: 'operator:'.$actor->id,
            correlationId: null,
        );
    }

    public function revokeAs(Operator $actor, Organization $organization, Operator $target, ?AccessScope $scope = null): void
    {
        $this->revoke()->revoke(
            new RevokeOrganizationMemberCommand($organization->id, $target->id, $actor->id),
            $scope ?? $this->scopeOf($actor),
            actor: 'operator:'.$actor->id,
            correlationId: null,
        );
    }

    public function changeRoleAs(Operator $actor, Organization $organization, Operator $target, string $role): void
    {
        $this->changeRole()->changeRole(
            new ChangeOrganizationMemberRoleCommand($organization->id, $target->id, $role, $actor->id),
            $this->scopeOf($actor),
            actor: 'operator:'.$actor->id,
            correlationId: null,
        );
    }

    public function acceptToken(string $token, string $password = 'a chosen passphrase', string $name = 'Invitee'): AcceptedOrganizationInvitation
    {
        return $this->accept()->accept(new AcceptOrganizationInvitationCommand($token, $name, $password));
    }

    /**
     * The clear token of a fresh invitation issued by `$actor`.
     */
    public function issueToken(Operator $actor, Organization $organization, string $email = 'invitee@acme.test', string $role = 'org_admin'): string
    {
        $token = $this->inviteAs($actor, $organization, $email, $role)->token;
        if ($token === null) {
            throw new LogicException('The creation response must carry the clear token.');
        }

        return $token;
    }

    public function onlyInvitation(): OrganizationInvitation
    {
        $all = $this->invitations->all();
        if (count($all) !== 1) {
            throw new LogicException('Expected exactly one invitation, got '.count($all).'.');
        }

        return $all[0];
    }

    /**
     * Runs `$work` and returns the refusal it raised, typed — so a test can
     * assert on its code, status or reason. Fails when nothing, or
     * something else, is thrown.
     *
     * @template T of \Throwable
     *
     * @param class-string<T> $expected
     * @return T
     */
    public static function refusal(string $expected, callable $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $thrown) {
            if ($thrown instanceof $expected) {
                return $thrown;
            }

            throw new LogicException(sprintf('Expected %s, got %s: %s', $expected, $thrown::class, $thrown->getMessage()), 0, $thrown);
        }

        throw new LogicException(sprintf('Expected %s, nothing was thrown.', $expected));
    }

    public function policy(): OrganizationMembershipPolicy
    {
        return new OrganizationMembershipPolicy($this->operators, $this->memberships);
    }

    public function invite(): InviteOrganizationMember
    {
        return new InviteOrganizationMember(
            $this->invitations,
            $this->memberships,
            $this->operators,
            $this->organizations,
            $this->policy(),
            $this->identifier,
            $this->audit,
            $this->clock,
            new PassthroughUnitOfWork,
        );
    }

    public function accept(): AcceptOrganizationInvitation
    {
        return new AcceptOrganizationInvitation(
            $this->invitations,
            $this->memberships,
            $this->operators,
            $this->challenges,
            $this->identifier,
            $this->audit,
            $this->clock,
            new PassthroughUnitOfWork,
        );
    }

    public function revoke(): RevokeOrganizationMember
    {
        return new RevokeOrganizationMember(
            $this->memberships,
            $this->organizations,
            $this->policy(),
            $this->audit,
            $this->clock,
            new PassthroughUnitOfWork,
        );
    }

    public function changeRole(): ChangeOrganizationMemberRole
    {
        return new ChangeOrganizationMemberRole(
            $this->memberships,
            $this->organizations,
            $this->policy(),
            $this->audit,
            $this->clock,
            new PassthroughUnitOfWork,
        );
    }

    public function list(): ListOrganizationMembers
    {
        return new ListOrganizationMembers($this->memberships, $this->operators, $this->organizations);
    }

    public function organization(string $name = 'Acme'): Organization
    {
        $organization = Organization::create('org-'.++$this->sequence, $name);
        $this->organizations->save($organization);

        return $organization;
    }

    public function account(string $email, ?OperatorRole $platformRole = null, string $password = 'correct horse battery', bool $mfaEnabled = false, bool $revoked = false): Operator
    {
        $operator = new Operator(
            id: 'op-'.++$this->sequence,
            email: $email,
            name: $email,
            passwordHash: Operator::hashPassword($password),
            role: $platformRole,
            mfaSecret: $mfaEnabled ? 'SECRET' : null,
            mfaEnabled: $mfaEnabled,
            mfaLastVerifiedTimestep: null,
            revokedAt: $revoked ? $this->clock->now() : null,
            createdAt: $this->clock->now(),
        );
        $this->operators->create($operator);

        return $operator;
    }

    public function member(Organization $organization, Operator $operator, OrganizationRole $role): OrganizationMembership
    {
        $membership = new OrganizationMembership(
            id: 'm-'.++$this->sequence,
            organizationId: $organization->id,
            operatorId: $operator->id,
            role: $role,
            createdAt: $this->clock->now(),
        );
        $this->memberships->save($membership);

        return $membership;
    }

    /**
     * The scope `ResolveAccessScope` would give the account: every
     * organization for a platform role, otherwise its memberships.
     */
    public function scopeOf(Operator $operator): AccessScope
    {
        if ($operator->role !== null) {
            return AccessScope::platform();
        }

        $organizationIds = [];
        $permissions = [];
        foreach ($this->memberships->findActiveByOperator($operator->id) as $membership) {
            $organizationIds[] = $membership->organizationId;
            $permissions[$membership->organizationId] = $membership->role->permissions();
        }

        return new AccessScope(false, $organizationIds, $permissions);
    }
}
