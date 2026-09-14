<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Application\Dto\CreateOrganizationCommand as Command;
use Core\Identity\Application\UseCase\CreateOrganization;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Str;

/**
 * A bootstrap tool, not a replacement for the Core Admin operator console
 * (Lot 6). Organizations and Projects are created rarely enough — by
 * platform operators setting up a new client — that a console command is a
 * reasonable stand-in until then.
 */
final class CreateOrganizationCommand extends ConsoleCommand
{
    protected $signature = 'identity:create-organization {name : The organization name}';

    protected $description = 'Create a new Organization';

    public function handle(CreateOrganization $createOrganization): int
    {
        $organization = $createOrganization(
            new Command(name: $this->argument('name')),
            actor: 'service:cli',
            correlationId: (string) Str::uuid(),
        );

        $this->info("Organization created: {$organization->id}");

        return self::SUCCESS;
    }
}
