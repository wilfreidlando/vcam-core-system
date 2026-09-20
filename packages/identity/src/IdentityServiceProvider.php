<?php

declare(strict_types=1);

namespace Core\Identity;

use Core\Identity\Application\UseCase\CreateOperator as CreateOperatorUseCase;
use Core\Identity\Application\UseCase\CreateOrganization as CreateOrganizationUseCase;
use Core\Identity\Application\UseCase\CreateProject as CreateProjectUseCase;
use Core\Identity\Application\UseCase\GetProjectUseCase;
use Core\Identity\Application\UseCase\ListOperatorsUseCase;
use Core\Identity\Application\UseCase\ListOrganizationsUseCase;
use Core\Identity\Application\UseCase\ListProjectsUseCase;
use Core\Identity\Application\UseCase\RevokeOperator as RevokeOperatorUseCase;
use Core\Identity\Application\UseCase\UpdateOperator as UpdateOperatorUseCase;
use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOrganizations;
use Core\Identity\Contracts\ListProjects;
use Core\Identity\Contracts\RevokeOperator;
use Core\Identity\Contracts\UpdateOperator;
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
use Core\Identity\Infrastructure\Security\AlwaysValidTotpProvider;
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
use Illuminate\Contracts\Foundation\Application;
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
        $this->app->bind(ListProjects::class, ListProjectsUseCase::class);
        $this->app->bind(ListOrganizations::class, ListOrganizationsUseCase::class);
        $this->app->bind(GetProject::class, GetProjectUseCase::class);
        $this->app->bind(CreateOperator::class, CreateOperatorUseCase::class);
        $this->app->bind(ListOperators::class, ListOperatorsUseCase::class);
        $this->app->bind(RevokeOperator::class, RevokeOperatorUseCase::class);
        $this->app->bind(UpdateOperator::class, UpdateOperatorUseCase::class);
        $this->app->bind(CreateOrganization::class, CreateOrganizationUseCase::class);
        $this->app->bind(CreateProject::class, CreateProjectUseCase::class);

        $this->app->bind(OperatorRepository::class, EloquentOperatorRepository::class);
        $this->app->bind(TotpProvider::class, function (Application $app): TotpProvider {
            // Both checks are required, deliberately — the environment
            // check happens here in code, not only via the config flag,
            // so no config value can ever enable this outside `local`
            // (see config/identity.php).
            if ($app->environment('local') && (bool) config('identity.disable_totp_in_local', false)) {
                return $app->make(AlwaysValidTotpProvider::class);
            }

            return $app->make(Google2faTotpProvider::class);
        });
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
