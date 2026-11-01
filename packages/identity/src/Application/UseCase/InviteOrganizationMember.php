<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Support\OrganizationMembershipPolicy;
use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\CreatedOrganizationInvitation;
use Core\Identity\Contracts\InviteOrganizationMember as InviteOrganizationMemberPort;
use Core\Identity\Contracts\InviteOrganizationMemberCommand;
use Core\Identity\Contracts\OrganizationInvitationAlreadyExists;
use Core\Identity\Contracts\OrganizationNotVisible;
use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OrganizationInvitation;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationInvitationRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0069 §4 — invites a member into an organization.
 *
 * The invitation carries a one-shot activation token: 32 random bytes,
 * kept only as a SHA-256 hash, valid for 72 hours. The clear value is
 * returned **once**, in the creation response, and never again
 * (ADR-0081 S-07): it is neither stored, nor audited, nor logged.
 */
final readonly class InviteOrganizationMember implements InviteOrganizationMemberPort
{
    public function __construct(
        private OrganizationInvitationRepository $invitations,
        private OrganizationMembershipRepository $memberships,
        private OperatorRepository $operators,
        private OrganizationRepository $organizations,
        private OrganizationMembershipPolicy $policy,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function invite(
        InviteOrganizationMemberCommand $command,
        AccessScope $scope,
        string $actor,
        ?string $correlationId,
    ): CreatedOrganizationInvitation {
        // ADR-0069 §2 / S-04 — outside the scope: 404, never 403.
        $this->policy->assertVisible($scope, $command->organizationId);

        $organization = $this->organizations->find($command->organizationId)
            ?? throw OrganizationNotVisible::withId($command->organizationId);

        $email = self::normalizeEmail($command->email);

        return $this->unitOfWork->transactional(function () use ($organization, $email, $command, $actor, $correlationId): CreatedOrganizationInvitation {
            // ADR-0069 — the owner's rows are locked FIRST, and the
            // actor's role is read and judged under that lock, exactly as
            // `RevokeOrganizationMember` does. Judged before it, a revoked
            // or demoted owner could still hand out an `org_owner` role:
            // the check and the lock were not the same instant.
            $this->memberships->lockActiveOwners($organization->id);

            // The requested role first: a platform role or an unknown role
            // is refused whoever asks.
            $role = $this->policy->resolveAssignableRole($command->role, $organization->id);

            // `members:write` in this organization, and only an owner
            // grants the owner role.
            $actorRole = $this->policy->assertCanManageMembers($command->actorOperatorId, $organization->id);
            $this->policy->assertCanGrant($actorRole, $organization->id, $role);

            $now = $this->clock->now();

            $existing = $this->operators->findByEmail($email);
            if ($existing !== null && $this->memberships->findActive($organization->id, $existing->id) !== null) {
                throw AlreadyOrganizationMember::forEmail($organization->id, $email);
            }

            // One activable invitation per email and organization.
            if ($this->invitations->findPendingByEmail($organization->id, $email, $now) !== null) {
                throw OrganizationInvitationAlreadyExists::forEmail($organization->id, $email);
            }

            $token = InvitationToken::issue($now);

            $invitation = new OrganizationInvitation(
                id: $this->identifier->generate(),
                organizationId: $organization->id,
                email: $email,
                role: $role,
                token: $token,
                invitedBy: $command->actorOperatorId,
                createdAt: $now,
            );

            $this->invitations->save($invitation);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'organization.member_invited',
                resource: 'organization:'.$organization->id,
                before: null,
                // Never the token, not even its hash: the audit trail
                // must hold nothing that opens an account.
                after: [
                    'invitation_id' => $invitation->id,
                    // ADR-0083 §4 — the journal records WHICH fields were
                    // set, never their values: an append-only, tamper-
                    // evident log could never erase a personal value.
                    // The invitation row itself carries the email.
                    'changed' => ['email'],
                    'role' => $role->value,
                    'expires_at' => $invitation->expiresAt()->format(DATE_ATOM),
                ],
                context: [],
                correlationId: $correlationId,
                occurredAt: $now,
                organizationId: $organization->id,
            ));

            return new CreatedOrganizationInvitation(
                id: $invitation->id,
                organizationId: $invitation->organizationId,
                email: $invitation->email,
                role: $invitation->role->value,
                // Read once, then forgotten by the token itself.
                token: $token->revealPlainText(),
                expiresAt: $invitation->expiresAt(),
            );
        });
    }

    /**
     * The email is stored lower-cased: the duplicate check and the
     * account lookup must not be fooled by a case difference.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
