<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Payment\Contracts\RegisterProviderApplication as RegisterProviderApplicationPort;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;

/**
 * Reachable from both the CLI (`payment:register-provider-application`) and
 * a forthcoming admin-only HTTP endpoint — a single place where a
 * Project's provider application is registered or rotated, whichever
 * caller triggers it.
 *
 * No audit trail is recorded here: `payment` is not permitted to depend on
 * `audit` (`tests/Architecture/ModuleGraph.php` — unlike `identity`,
 * ADR-0029). The admin caller, which IS permitted to depend on `audit`,
 * records its own entry after calling `register()`.
 */
final readonly class RegisterProviderApplication implements RegisterProviderApplicationPort
{
    public function __construct(
        private ProviderApplicationRepository $applications,
        private GetProject $getProject,
    ) {}

    public function register(RegisterProviderApplicationCommand $command): void
    {
        // Validates the Project exists — throws a RuntimeException subtype
        // on its own if it doesn't, which we let propagate (Contracts must
        // not reference the concrete Identity exception type, G3c).
        $this->getProject->get(new GetProjectCommand($command->projectId));

        $application = new ProviderApplication(
            projectId: $command->projectId,
            providerCode: $command->providerCode,
            publicKey: $command->publicKey,
            privateKey: $command->privateKey,
        );

        $this->applications->save($application);
    }
}
