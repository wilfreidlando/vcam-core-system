<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand as Command;
use Core\Identity\Domain\Exception\InvalidOperatorRole;
use Core\Identity\OperatorRole;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

final class CreateOperatorCommand extends ConsoleCommand
{
    protected $signature = 'identity:create-operator
        {email : The operator\'s email address}
        {name : The operator\'s display name}
        {role : platform_admin, finance_operator, support_operator, provider_manager, or read_only_auditor}';

    protected $description = 'Create a platform operator. The plaintext password is shown once and never stored.';

    public function handle(CreateOperator $createOperator): int
    {
        /** @var string $email */
        $email = $this->argument('email');
        /** @var string $name */
        $name = $this->argument('name');
        /** @var string $role */
        $role = $this->argument('role');

        try {
            $created = $createOperator->create(
                new Command(email: $email, name: $name, role: $role),
                actor: 'service:cli',
                correlationId: (string) Str::uuid(),
            );
        } catch (InvalidOperatorRole) {
            $this->error('role must be one of: '.implode(', ', array_column(OperatorRole::cases(), 'value')));

            return self::FAILURE;
        }

        $this->info("Operator created: {$created->operatorId}");
        $this->newLine();
        $this->warn('This password is shown once and cannot be retrieved again:');
        $this->line($created->plaintextPassword);
        $this->newLine();
        $this->line('MFA enrollment happens on first login (POST /v1/operators/login).');

        return self::SUCCESS;
    }
}
