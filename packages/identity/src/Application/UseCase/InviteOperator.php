<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\CreatedOperatorInvitation;
use Core\Identity\Contracts\InviteOperator as InviteOperatorPort;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationAlreadyExists;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\Domain\Model\InvitationToken;
use Core\Identity\Domain\Model\OperatorInvitation;
use Core\Identity\Domain\Port\OperatorInvitationRepository;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Support\Clock;
use Core\Support\Identifier;
use Core\Support\UnitOfWork;

/**
 * ADR-0081 S-07 — invites an email address to hold a console account.
 *
 * The account does not exist yet. The invitation carries a one-shot
 * activation token: 32 random bytes, kept only as a SHA-256 hash,
 * valid for 72 hours. The clear value is returned **once**, in the
 * creation response, and never again: it is neither stored, nor
 * audited, nor logged. The invitee chooses their own password when
 * they activate the invitation — no password is chosen by the system
 * and none is transmitted (ADR-0069 §4).
 *
 * The same mechanism as the organization invitations (ADR-0069 §4),
 * with one difference: there is no organization here, and the account
 * carries a **platform** role — or none at all (ADR-0069 §1).
 */
final readonly class InviteOperator implements InviteOperatorPort
{
    public function __construct(
        private OperatorInvitationRepository $invitations,
        private OperatorRepository $operators,
        private Identifier $identifier,
        private AuditLogger $audit,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    public function invite(
        InviteOperatorCommand $command,
        string $actor,
        ?string $correlationId,
    ): CreatedOperatorInvitation {
        // ADR-0069 §1: an empty role means "no platform role" — the
        // account then lives in its organizations' memberships only.
        $role = trim($command->role) === ''
            ? null
            : (OperatorRole::tryFrom($command->role) ?? throw InvalidOperatorRole::withValue($command->role));

        // One mailbox, one account (ADR-0069 §1): the comparison is
        // case-insensitive, so the address is stored lower-cased.
        $email = strtolower(trim($command->email));

        return $this->unitOfWork->transactional(function () use ($email, $role, $command, $actor, $correlationId): CreatedOperatorInvitation {
            $now = $this->clock->now();

            // The invitation creates the account. An address that already
            // carries one is refused: the platform operator who wants to
            // grant a platform role to an existing account uses the
            // operator's own page — the invitation path never touches an
            // account that already exists.
            if ($this->operators->findByEmail($email) !== null) {
                throw OperatorEmailAlreadyTaken::forEmail($email);
            }

            // One activable invitation per mailbox.
            if ($this->invitations->findPendingByEmail($email, $now) !== null) {
                throw OperatorInvitationAlreadyExists::forEmail($email);
            }

            $token = InvitationToken::issue($now);

            $invitation = new OperatorInvitation(
                id: $this->identifier->generate(),
                email: $email,
                role: $role,
                token: $token,
                invitedBy: $command->invitedBy,
                createdAt: $now,
            );

            $this->invitations->save($invitation);

            $this->audit->record(new AuditEntry(
                actor: $actor,
                action: 'operator.invited',
                resource: 'operator_invitation:'.$invitation->id,
                before: null,
                after: [
                    'invitation_id' => $invitation->id,
                    // ADR-0083 §4 — the journal records WHICH fields were
                    // set, never their values: an append-only, tamper-
                    // evident log could never erase a personal value.
                    // The invitation row itself carries the email.
                    'changed' => ['email'],
                    'role' => $role?->value,
                    'expires_at' => $invitation->expiresAt()->format(DATE_ATOM),
                ],
                context: [],
                correlationId: $correlationId,
                occurredAt: $now,
            ));

            return new CreatedOperatorInvitation(
                id: $invitation->id,
                email: $invitation->email,
                role: $role?->value,
                // Read once, then forgotten by the token itself.
                token: $token->revealPlainText(),
                expiresAt: $invitation->expiresAt(),
            );
        });
    }
}
