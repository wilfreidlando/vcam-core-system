<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Console\Commands;

use Core\Payment\Contracts\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand as Command;
use Core\Support\Environment;
use Illuminate\Console\Command as ConsoleCommand;
use RuntimeException;

/**
 * Bootstrap tool, kept alongside an admin-only HTTP endpoint (built on the
 * trusted operator-authenticated plane, not the Project/API-key one) that
 * a sibling agent is adding for the same capability — both converge on the
 * same `Contracts\RegisterProviderApplication` port. Registers (or
 * rotates, if one already exists — `save()` is an upsert) a Project's own
 * application with a payment provider — e.g. its MyCoolPay public/private
 * key pair, obtained from the MyCoolPay merchant dashboard. There is still
 * no self-service Project-facing flow for this: a Project cannot grant
 * itself another Project's payment credentials, so both entry points
 * remain operator/CLI-only, never `project.auth`.
 *
 * Going through the use case now validates the Project exists before
 * saving (`RuntimeException` on failure) — it does NOT gain an audit
 * trail: `payment` is not permitted to depend on `audit`
 * (`tests/Architecture/ModuleGraph.php`, unlike `identity` — ADR-0029),
 * so this CLI path remains unaudited, same as before. The admin HTTP
 * endpoint, built on a module that IS permitted to depend on `audit`,
 * records its own entry after calling this same port.
 */
final class RegisterProviderApplicationCommand extends ConsoleCommand
{
    protected $signature = 'payment:register-provider-application
        {project : The Project id}
        {provider : The provider code, e.g. my-coolpay}
        {public-key : The application\'s public key}
        {private-key : The application\'s private key (kept encrypted at rest)}
        {--environment=live : live or sandbox (ADR-0060)}';

    protected $description = "Register (or rotate) a Project's own application/merchant-account with a payment provider.";

    public function handle(RegisterProviderApplication $registerProviderApplication): int
    {
        /** @var string $projectId */
        $projectId = $this->argument('project');
        /** @var string $providerCode */
        $providerCode = $this->argument('provider');
        /** @var string $publicKey */
        $publicKey = $this->argument('public-key');
        /** @var string $privateKey */
        $privateKey = $this->argument('private-key');

        try {
            $registerProviderApplication->register(new Command(
                projectId: $projectId,
                providerCode: $providerCode,
                publicKey: $publicKey,
                privateKey: $privateKey,
                environment: Environment::tryFrom((string) $this->option('environment')) ?? Environment::Live,
            ));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Registered {$providerCode} application for project {$projectId} (public key {$publicKey}).");

        return self::SUCCESS;
    }
}
