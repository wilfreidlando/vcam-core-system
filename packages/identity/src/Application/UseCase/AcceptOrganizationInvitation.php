<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\AcceptedOrganizationInvitation;
use Core\Identity\Contracts\AcceptOrganizationInvitation as AcceptOrganizationInvitationPort;
use Core\Identity\Contracts\AcceptOrganizationInvitationCommand;
use Core\Identity\Contracts\AlreadyOrganizationMember;
use Core\Identity\Contracts\InvitationAccountPasswordInvalid;
use Core\Identity\Contracts\InvitationNotUsable;
use Core\Identity\Contracts\InvitationPasswordTooWeak;
use Core\Identity\Contracts\OperatorAlreadyExists;
use Core\Identity\Contracts\OrganizationInvitationNotFound;
use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\OrganizationInvitation;
use Core\Identity\Domain\Model\OrganizationMembership;
use Core\Identity\Domain\Port\LoginChallengeCodec;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OrganizationInvitationRepository;
use Core\Identity\Domain\Port\OrganizationMembershipRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;

/**
 * ADR-0069 §4 — consumes an activation token: the invited email joins
 * the organization with the invited role.
 *
 * Two cases, decided by whether the invited email already carries a
 * console account:
 *
 *  - **no account**: one is created with **no platform role** and the
 *    password the invitee chooses here — no password is chosen by the
 *    system and none is transmitted (ADR-0081 S-07);
 *  - **an active account**: it joins the organization, and the password
 *    presented must be its **current** one — verified, never replaced.
 *    The token proves the invitation reached someone; it does not prove
 *    that someone holds the account. Letting the token reset the password
 *    would turn every invitation into an account takeover (of the account
 *    and of every other organization it belongs to), so it never does.
 *    A revoked account is refused: only the platform reinstates it.
 *
 * Either way, the sign-in continues on the **existing** MFA flow
 * (ADR-0044/0046): the challenge returned is exactly the one
 * `LoginOperator` would issue for the account — `mfa_enrollment` for an
 * account without MFA (always the case for a new one), `mfa` otherwise.
 * No session is opened here, and no second mechanism exists.
 *
 * Single use under concurrency: the invitation row is read with
 * `SELECT … FOR UPDATE` inside the transaction that creates the account
 * and the membership and marks the invitation accepted. Of two
 * simultaneous acceptances, the second waits for the first to commit,
 * then sees `accepted_at` set and is refused.
 */
final readonly class AcceptOrganizationInvitation implements AcceptOrganizationInvitationPort
{
    /** ADR-0081 — a console password is chosen by its holder. */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    /** Same lifetime as the challenge of the login step. */
    private const int CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private OrganizationInvitationRepository $invitations,
        private OrganizationMembershipRepository $memberships,
        private OperatorRepository $operators,
        private LoginChallengeCodec $challenges,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function accept(AcceptOrganizationInvitationCommand $command): AcceptedOrganizationInvitation
    {
        $presentedToken = trim($command->token);
        $tokenHash = InvitationToken::hashOf($presentedToken);

        return $this->unitOfWork->transactional(function () use ($command, $presentedToken, $tokenHash): AcceptedOrganizationInvitation {
            $now = $this->clock->now();

            $invitation = $this->invitations->lockByTokenHash($tokenHash);

            // An unknown token and a forged one are indistinguishable from
            // the outside: the same 404 answers both.
            if ($invitation === null || ! $invitation->token->matches($presentedToken)) {
                throw OrganizationInvitationNotFound::forToken();
            }

            $this->assertUsable($invitation, $now);

            $existing = $this->operators->findByEmail($invitation->email);

            if ($existing === null) {
                $operator = $this->createAccount($invitation, $command, $now);
                $accountCreated = true;
            } else {
                $this->assertHoldsExistingAccount($existing, $invitation, $command->password);
                $operator = $existing;
                $accountCreated = false;
            }

            if ($this->memberships->findActive($invitation->organizationId, $operator->id) !== null) {
                throw AlreadyOrganizationMember::forEmail($invitation->organizationId, $invitation->email);
            }

            $membership = new OrganizationMembership(
                id: $this->identifier->generate(),
                organizationId: $invitation->organizationId,
                operatorId: $operator->id,
                role: $invitation->role,
                createdAt: $now,
            );
            $this->memberships->save($membership);

            // Consumed: a second presentation is refused.
            $invitation->accept($now);
            $this->invitations->save($invitation);

            // ADR-0044 / ADR-0046 — the same challenge the login step
            // issues for this account.
            $purpose = $operator->mfaEnabled() ? 'mfa' : 'mfa_enrollment';
            $challengeToken = $this->challenges->issue(
                $operator->id,
                $purpose,
                $now->modify('+'.self::CHALLENGE_TTL_SECONDS.' seconds'),
            );

            $this->audit->record(new AuditEntry(
                actor: 'operator:'.$operator->id,
                action: 'organization.invitation_accepted',
                resource: 'organization:'.$invitation->organizationId,
                before: null,
                after: [
                    'invitation_id' => $invitation->id,
                    'membership_id' => $membership->id,
                    'operator_id' => $operator->id,
                    // ADR-0083 §4 — which fields were set, never their
                    // values: the journal is append-only and could never
                    // erase a personal value.
                    'changed' => ['email'],
                    'role' => $invitation->role->value,
                    'account_created' => $accountCreated,
                ],
                context: [],
                correlationId: null,
                occurredAt: $now,
                organizationId: $invitation->organizationId,
            ));

            return new AcceptedOrganizationInvitation(
                operatorId: $operator->id,
                organizationId: $invitation->organizationId,
                role: $invitation->role->value,
                email: $invitation->email,
                accountCreated: $accountCreated,
                challengePurpose: $purpose,
                challengeToken: $challengeToken,
            );
        });
    }

    /**
     * @throws InvitationNotUsable
     */
    private function assertUsable(OrganizationInvitation $invitation, DateTimeImmutable $now): void
    {
        if ($invitation->isRevoked()) {
            throw InvitationNotUsable::revoked($invitation->id);
        }

        if ($invitation->isAccepted()) {
            throw InvitationNotUsable::alreadyAccepted($invitation->id);
        }

        if ($invitation->isExpired($now)) {
            throw InvitationNotUsable::expired($invitation->id);
        }
    }

    /**
     * @throws InvitationPasswordTooWeak
     */
    private function createAccount(OrganizationInvitation $invitation, AcceptOrganizationInvitationCommand $command, DateTimeImmutable $now): Operator
    {
        if (mb_strlen($command->password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw InvitationPasswordTooWeak::withMinimumLength(self::MINIMUM_PASSWORD_LENGTH);
        }

        $name = trim($command->name);

        $operator = new Operator(
            id: $this->identifier->generate(),
            // The account is created for the email the invitation was
            // issued to — never for another one.
            email: $invitation->email,
            name: $name === '' ? $invitation->email : $name,
            passwordHash: Operator::hashPassword($command->password),
            // ADR-0069 §1 — an organization member holds no platform
            // role: it sees the organizations it belongs to, and them only.
            role: null,
            mfaSecret: null,
            mfaEnabled: false,
            mfaLastVerifiedTimestep: null,
            revokedAt: null,
            createdAt: $now,
        );

        $this->operators->create($operator);

        return $operator;
    }

    /**
     * The existing account's password is verified, never replaced.
     *
     * @throws OperatorAlreadyExists when the account is revoked.
     * @throws InvitationAccountPasswordInvalid when the password is not the account's.
     */
    private function assertHoldsExistingAccount(Operator $operator, OrganizationInvitation $invitation, string $password): void
    {
        if (! $operator->verifyPassword($password)) {
            throw InvitationAccountPasswordInvalid::make();
        }

        // Checked after the password, so a revoked account is only
        // confirmed to someone who knows its password.
        if ($operator->isRevoked()) {
            throw OperatorAlreadyExists::revoked($invitation->email);
        }
    }
}
