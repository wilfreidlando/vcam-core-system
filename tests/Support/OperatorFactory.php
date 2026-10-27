<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\OperatorRole;

/**
 * ADR-0081 S-07 — a console account is created by an activation token,
 * never with a password chosen by the system.
 *
 * A test that needs to sign in as an operator therefore chooses the
 * password ITSELF, at the activation — the same path a real invitee
 * takes. The password is a fixture constant, known to the test, and it
 * never travels through a production code path.
 */
final class OperatorFactory
{
    /** The password every operator created by this factory signs in with. */
    public const string PASSWORD = 'a known passphrase';

    /**
     * Invites an email address, then activates the invitation with the
     * chosen password. Returns the id of the account created.
     */
    public static function create(
        string $email,
        ?OperatorRole $role = null,
        string $password = self::PASSWORD,
    ): string {
        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand($email, $role instanceof OperatorRole ? $role->value : ''),
            actor: 'service:test',
            correlationId: null,
        );

        $accepted = app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: (string) $invitation->token,
            name: 'Test',
            password: $password,
        ));

        return $accepted->operatorId;
    }
}
