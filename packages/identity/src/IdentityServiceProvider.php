<?php

declare(strict_types=1);

namespace Core\Identity;

use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Identity\Domain\Port\ApiKeySecretGenerator;
use Core\Identity\Domain\Port\LoginChallengeCodec;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Domain\Port\OperatorTokenResolver;
use Core\Identity\Domain\Port\OperatorTokenRevoker;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Identity\Domain\Port\TotpProvider;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentApiKeyRepository;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentOperatorRepository;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentOrganizationRepository;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentProjectRepository;
use Core\Identity\Infrastructure\Security\EncryptedLoginChallengeCodec;
use Core\Identity\Infrastructure\Security\Google2faTotpProvider;
use Core\Identity\Infrastructure\Security\RandomApiKeySecretGenerator;
use Core\Identity\Infrastructure\Security\SanctumOperatorTokenIssuer;
use Core\Identity\Infrastructure\Security\SanctumOperatorTokenResolver;
use Core\Identity\Infrastructure\Security\SanctumOperatorTokenRevoker;
use Core\Identity\Presentation\Console\Commands\CreateOperatorCommand;
use Core\Identity\Presentation\Console\Commands\CreateOrganizationCommand;
use Core\Identity\Presentation\Console\Commands\CreateProjectCommand;
use Core\Identity\Presentation\Console\Commands\IssueApiKeyCommand;
use Core\Identity\Presentation\Console\Commands\RevokeApiKeyCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: identity
 * Responsibility: Organizations, Projects, API keys (machine identity, Plan 2);
 * Operators, RBAC, MFA (platform identity, Plan 1, ADR-0044).
 * Delivery lot: Lot 1 (Plan 2), Lot 6 (Plan 1)
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationRepository::class, EloquentOrganizationRepository::class);
        $this->app->bind(ProjectRepository::class, EloquentProjectRepository::class);
        $this->app->bind(ApiKeyRepository::class, EloquentApiKeyRepository::class);
        $this->app->bind(ApiKeySecretGenerator::class, RandomApiKeySecretGenerator::class);

        $this->app->bind(OperatorRepository::class, EloquentOperatorRepository::class);
        $this->app->bind(TotpProvider::class, Google2faTotpProvider::class);
        $this->app->bind(OperatorTokenIssuer::class, SanctumOperatorTokenIssuer::class);
        $this->app->bind(OperatorTokenResolver::class, SanctumOperatorTokenResolver::class);
        $this->app->bind(OperatorTokenRevoker::class, SanctumOperatorTokenRevoker::class);
        $this->app->bind(LoginChallengeCodec::class, EncryptedLoginChallengeCodec::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateOrganizationCommand::class,
                CreateProjectCommand::class,
                IssueApiKeyCommand::class,
                RevokeApiKeyCommand::class,
                CreateOperatorCommand::class,
            ]);
        }
    }
}
