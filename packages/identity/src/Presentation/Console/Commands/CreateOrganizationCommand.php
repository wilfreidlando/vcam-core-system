<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand as Command;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

/**
 * A bootstrap tool, kept alongside the admin console's self-service
 * `POST /v1/admin/organizations` (both converge on the same
 * `Contracts\CreateOrganization` port) — useful for scripting/seeding
 * without going through HTTP.
 */
final class CreateOrganizationCommand extends ConsoleCommand
{
    protected $signature = 'identity:create-organization {name : The organization name}';

    protected $description = 'Create a new Organization';

    public function handle(CreateOrganization $createOrganization): int
    {
        $organization = $createOrganization->create(
            new Command(name: $this->argument('name')),
            actor: 'service:cli',
            correlationId: (string) Str::uuid(),
        );

        $this->info("Organization created: {$organization->id}");

        return self::SUCCESS;
    }
}
