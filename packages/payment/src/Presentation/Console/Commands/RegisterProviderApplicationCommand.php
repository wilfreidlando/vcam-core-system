<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Console\Commands;

use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Illuminate\Console\Command as ConsoleCommand;

/**
 * Bootstrap tool, not self-service (mirrors `identity:issue-api-key`):
 * registers (or rotates, if one already exists — `save()` is an upsert)
 * the Project's own application with a payment provider — e.g. its
 * MyCoolPay public/private key pair, obtained from the MyCoolPay
 * merchant dashboard. There is no HTTP endpoint for this: a Project
 * cannot grant itself another Project's payment credentials, and there
 * is no self-service onboarding flow yet for a new payment provider
 * application (the same gap `identity:create-organization`/
 * `identity:issue-api-key` document for their own concerns).
 */
final class RegisterProviderApplicationCommand extends ConsoleCommand
{
    protected $signature = 'payment:register-provider-application
        {project : The Project id}
        {provider : The provider code, e.g. my-coolpay}
        {public-key : The application\'s public key}
        {private-key : The application\'s private key (kept encrypted at rest)}';

    protected $description = "Register (or rotate) a Project's own application/merchant-account with a payment provider.";

    public function handle(ProviderApplicationRepository $applications): int
    {
        /** @var string $projectId */
        $projectId = $this->argument('project');
        /** @var string $providerCode */
        $providerCode = $this->argument('provider');
        /** @var string $publicKey */
        $publicKey = $this->argument('public-key');
        /** @var string $privateKey */
        $privateKey = $this->argument('private-key');

        $applications->save(new ProviderApplication(
            projectId: $projectId,
            providerCode: $providerCode,
            publicKey: $publicKey,
            privateKey: $privateKey,
        ));

        $this->info("Registered {$providerCode} application for project {$projectId} (public key {$publicKey}).");

        return self::SUCCESS;
    }
}
