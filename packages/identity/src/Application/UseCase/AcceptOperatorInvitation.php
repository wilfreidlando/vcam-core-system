<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\AcceptedOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitation as AcceptOperatorInvitationPort;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\InvitationPasswordTooWeak;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationNotFound;
use Core\Identity\Contracts\OperatorInvitationNotUsable;
use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Model\OperatorInvitation;
use Core\Identity\Domain\Port\LoginChallengeCodec;
use Core\Identity\Domain\Port\OperatorInvitationRepository;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;
use DateTimeImmutable;

/**
 * ADR-0081 S-07 — consumes an activation token: the invited email gets
 * its console account, with the password **it** chooses.
 *
 * The account is created here, not at the invitation: an invitation
 * that never gets activated leaves no account behind, and no password
 * is ever chosen by the system (ADR-0069 §4, ADR-0081 S-07).
 *
 * The sign-in continues on the **existing** MFA flow (ADR-0044/0046):
 * the challenge returned is exactly the one `LoginOperator` would issue
 * for the account — `mfa_enrollment` for an account without MFA (always
 * the case for a new one). No session is opened here, and no second
 * mechanism exists.
 *
 * Single use under concurrency: the invitation row is read with
 * `SELECT … FOR UPDATE` inside the transaction that creates the account
 * and marks the invitation accepted. Of two simultaneous acceptances,
 * the second waits for the first to commit, then sees `accepted_at` set
 * and is refused.
 */
final readonly class AcceptOperatorInvitation implements AcceptOperatorInvitationPort
{
    /** ADR-0081 — a console password is chosen by its holder. */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    /** Same lifetime as the challenge of the login step. */
    private const int CHALLENGE_TTL_SECONDS = 300;

    public function __construct(
        private OperatorInvitationRepository $invitations,
        private OperatorRepository $operators,
        private LoginChallengeCodec $challenges,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function accept(AcceptOperatorInvitationCommand $command): AcceptedOperatorInvitation
    {
        $presentedToken = trim($command->token);
        $tokenHash = InvitationToken::hashOf($presentedToken);

        return $this->unitOfWork->transactional(function () use ($command, $presentedToken, $tokenHash): AcceptedOperatorInvitation {
            $now = $this->clock->now();

            $invitation = $this->invitations->lockByTokenHash($tokenHash);

            // An unknown token and a forged one are indistinguishable from
            // the outside: the same 404 answers both.
            if ($invitation === null || ! $invitation->token->matches($presentedToken)) {
                throw OperatorInvitationNotFound::forToken();
            }

            $this->assertUsable($invitation, $now);

            // The account may have been created between the invitation
            // and this activation (another invitation for the same
            // mailbox, a manual provisioning): one mailbox, one account.
            if ($this->operators->findByEmail($invitation->email) !== null) {
                throw OperatorEmailAlreadyTaken::forEmail($invitation->email);
            }

            if (mb_strlen($command->password) < self::MINIMUM_PASSWORD_LENGTH) {
                throw InvitationPasswordTooWeak::withMinimumLength(self::MINIMUM_PASSWORD_LENGTH);
            }

            $operator = $this->createAccount($invitation, $command, $now);

            // Consumed: a second presentation is refused.
            $invitation->accept($now);
            $this->invitations->save($invitation);

            $purpose = 'mfa_enrollment';
            $challengeToken = $this->challenges->issue(
                $operator->id,
                $purpose,
                $now->modify('+'.self::CHALLENGE_TTL_SECONDS.' seconds'),
            );

            $this->audit->record(new AuditEntry(
                actor: 'operator:'.$operator->id,
                action: 'operator.invitation_accepted',
                resource: 'operator:'.$operator->id,
                before: null,
                after: [
                    'invitation_id' => $invitation->id,
                    'operator_id' => $operator->id,
                    // ADR-0083 §4 — which fields were set, never their
                    // values: the journal is append-only and could never
                    // erase a personal value.
                    'changed' => ['email'],
                    'role' => $invitation->role?->value,
                    'account_created' => true,
                ],
                context: [],
                correlationId: null,
                occurredAt: $now,
            ));

            return new AcceptedOperatorInvitation(
                operatorId: $operator->id,
                email: $operator->email,
                role: $invitation->role?->value,
                accountCreated: true,
                challengePurpose: $purpose,
                challengeToken: $challengeToken,
            );
        });
    }

    /**
     * @throws OperatorInvitationNotUsable
     */
    private function assertUsable(OperatorInvitation $invitation, DateTimeImmutable $now): void
    {
        if ($invitation->isRevoked()) {
            throw OperatorInvitationNotUsable::revoked($invitation->id);
        }

        if ($invitation->isAccepted()) {
            throw OperatorInvitationNotUsable::alreadyAccepted($invitation->id);
        }

        if ($invitation->isExpired($now)) {
            throw OperatorInvitationNotUsable::expired($invitation->id);
        }
    }

    /**
     * @throws InvitationPasswordTooWeak
     */
    private function createAccount(
        OperatorInvitation $invitation,
        AcceptOperatorInvitationCommand $command,
        DateTimeImmutable $now,
    ): Operator {
        $name = trim($command->name);

        $operator = new Operator(
            id: $this->identifier->generate(),
            // The account is created for the email the invitation was
            // issued to — never for another one.
            email: $invitation->email,
            name: $name === '' ? $invitation->email : $name,
            passwordHash: Operator::hashPassword($command->password),
            // The platform role travels with the invitation; an account
            // without one sees only its organizations (ADR-0069 §1).
            role: $invitation->role,
            // MFA is enrolled at the first sign-in, never here
            // (ADR-0044/0046).
            mfaSecret: null,
            mfaEnabled: false,
            mfaLastVerifiedTimestep: null,
            revokedAt: null,
            createdAt: $now,
        );

        $this->operators->create($operator);

        return $operator;
    }
}
