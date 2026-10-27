<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand as Command;
use Core\Identity\Contracts\OperatorEmailAlreadyTaken;
use Core\Identity\Contracts\OperatorInvitationAlreadyExists;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\OperatorRole;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

final class InviteOperatorCommand extends ConsoleCommand
{
    protected $signature = 'identity:invite-operator
        {email : The email address to invite}
        {role : platform_admin, finance_operator, support_operator, provider_manager, or read_only_auditor}';

    protected $description = 'Invite a console account. The activation token is shown once; the invitee chooses their own password.';

    public function handle(InviteOperator $inviteOperator): int
    {
        /** @var string $email */
        $email = $this->argument('email');
        /** @var string $role */
        $role = $this->argument('role');

        try {
            $invitation = $inviteOperator->invite(
                new Command(email: $email, role: $role),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (InvalidOperatorRole) {
            $this->error('role must be one of: '.implode(', ', array_column(OperatorRole::cases(), 'value')));

            return self::FAILURE;
        } catch (OperatorEmailAlreadyTaken) {
            // ADR-0069 §1 — one console account per mailbox.
            $this->error('A console account already exists for this email address.');

            return self::FAILURE;
        } catch (OperatorInvitationAlreadyExists) {
            $this->error('An invitation for this email address is already pending.');

            return self::FAILURE;
        }

        $this->info("Invitation created: {$invitation->id}");
        $this->newLine();
        $this->warn('This activation token is shown once and cannot be retrieved again:');
        $this->line((string) $invitation->token);
        $this->newLine();
        $this->line('It is valid for 72 hours. The invitee chooses their own password and enrols their MFA at:');
        $this->line('  POST /v1/admin/operator-invitations/accept');

        return self::SUCCESS;
    }
}
