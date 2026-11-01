<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Integration;

use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationAlreadyExists;
use Core\Identity\Contracts\OperatorInvitationNotFound;
use Core\Identity\Contracts\OperatorInvitationNotUsable;
use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use Core\Support\Identifier;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0081 S-07 — a console account is created by an activation token,
 * never with a password chosen by the system.
 *
 * Against a real PostgreSQL database: the invitation row, its hash, the
 * one-shot consumption and the account it creates are all real.
 */
final class OperatorInvitationFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_invites_an_email_and_activates_it_into_an_account_with_the_chosen_password(): void
    {
        $inviter = $this->seedOperator('platform@example.test', OperatorRole::PlatformAdmin);

        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand('Invitee@Example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );

        self::assertNotNull($invitation->token);
        self::assertSame('invitee@example.test', $invitation->email);
        self::assertSame('platform_admin', $invitation->role);

        // The token is stored hashed, never in clear.
        $row = DB::table('operator_invitations')->where('id', $invitation->id)->first();
        self::assertNotNull($row);
        self::assertNotSame($invitation->token, $row->token_hash);
        self::assertSame(hash('sha256', $invitation->token), $row->token_hash);

        $accepted = app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: $invitation->token,
            name: 'Invitee',
            password: 'a chosen passphrase',
        ));

        self::assertTrue($accepted->accountCreated);
        self::assertSame('mfa_enrollment', $accepted->challengePurpose);

        $operator = app(OperatorRepository::class)->find($accepted->operatorId);
        self::assertNotNull($operator);
        self::assertSame('invitee@example.test', $operator->email);
        self::assertSame('Invitee', $operator->name);
        self::assertSame(OperatorRole::PlatformAdmin, $operator->role);
        self::assertTrue($operator->verifyPassword('a chosen passphrase'));
        self::assertFalse($operator->mfaEnabled());

        // The invitation is consumed.
        self::assertNotNull(
            DB::table('operator_invitations')->where('id', $invitation->id)->value('accepted_at')
        );
    }

    #[Test]
    public function the_activation_token_is_usable_once(): void
    {
        $inviter = $this->seedOperator('platform@example.test', OperatorRole::PlatformAdmin);
        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand('invitee@example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );

        app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: (string) $invitation->token,
            name: 'Invitee',
            password: 'a chosen passphrase',
        ));

        $this->expectException(OperatorInvitationNotUsable::class);
        app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: (string) $invitation->token,
            name: 'Invitee',
            password: 'another passphrase!',
        ));
    }

    #[Test]
    public function an_unknown_token_is_refused_as_not_found(): void
    {
        $this->expectException(OperatorInvitationNotFound::class);
        app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: str_repeat('a', 43),
            name: 'Invitee',
            password: 'a chosen passphrase',
        ));
    }

    #[Test]
    public function an_expired_invitation_is_refused(): void
    {
        $inviter = $this->seedOperator('platform@example.test', OperatorRole::PlatformAdmin);
        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand('invitee@example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );

        DB::table('operator_invitations')
            ->where('id', $invitation->id)
            ->update(['expires_at' => now()->subSecond()]);

        $this->expectException(OperatorInvitationNotUsable::class);
        app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(
            token: (string) $invitation->token,
            name: 'Invitee',
            password: 'a chosen passphrase',
        ));
    }

    #[Test]
    public function an_email_that_already_carries_an_account_cannot_be_invited(): void
    {
        $inviter = $this->seedOperator('platform@example.test', OperatorRole::PlatformAdmin);
        $this->seedOperator('taken@example.test', OperatorRole::SupportOperator);

        $this->expectException(OperatorEmailAlreadyTaken::class);
        app(InviteOperator::class)->invite(
            new InviteOperatorCommand('Taken@Example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );
    }

    #[Test]
    public function a_second_pending_invitation_for_the_same_email_is_refused(): void
    {
        $inviter = $this->seedOperator('platform@example.test', OperatorRole::PlatformAdmin);

        app(InviteOperator::class)->invite(
            new InviteOperatorCommand('invitee@example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );

        $this->expectException(OperatorInvitationAlreadyExists::class);
        app(InviteOperator::class)->invite(
            new InviteOperatorCommand('invitee@example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );
    }

    #[Test]
    public function the_invitation_is_audited_without_the_token_nor_the_email_value(): void
    {
        $inviter = $this->seedOperator('platform@example.test', OperatorRole::PlatformAdmin);

        $invitation = app(InviteOperator::class)->invite(
            new InviteOperatorCommand('invitee@example.test', 'platform_admin'),
            'operator:'.$inviter->id,
            null,
        );

        $entry = DB::table('audit_log')
            ->where('action', 'operator.invited')
            ->orderByDesc('created_at')
            ->first();

        self::assertNotNull($entry);
        $journal = (string) json_encode([$entry->before, $entry->after, $entry->context]);
        self::assertStringNotContainsString((string) $invitation->token, $journal);
        // ADR-0083 §4 — the journal records WHICH fields changed, never
        // their values.
        self::assertStringNotContainsString('invitee@example.test', $journal);
    }

    private function seedOperator(string $email, OperatorRole $role): Operator
    {
        $operator = new Operator(
            id: app(Identifier::class)->generate(),
            email: $email,
            name: 'Seeded',
            passwordHash: Operator::hashPassword('a seeded passphrase'),
            role: $role,
            mfaSecret: null,
            mfaEnabled: false,
            mfaLastVerifiedTimestep: null,
            revokedAt: null,
            createdAt: new DateTimeImmutable('2026-10-27T10:00:00Z'),
        );

        app(OperatorRepository::class)->create($operator);

        return $operator;
    }
}
