<?php

declare(strict_types=1);

namespace Core\Identity;

use Core\Identity\Domain\Port\ApiKeyRepository;
use Core\Identity\Domain\Port\ApiKeySecretGenerator;
use Core\Identity\Domain\Port\OrganizationRepository;
use Core\Identity\Domain\Port\ProjectRepository;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentApiKeyRepository;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentOrganizationRepository;
use Core\Identity\Infrastructure\Persistence\Eloquent\EloquentProjectRepository;
use Core\Identity\Infrastructure\Security\RandomApiKeySecretGenerator;
use Core\Identity\Presentation\Console\Commands\CreateOrganizationCommand;
use Core\Identity\Presentation\Console\Commands\CreateProjectCommand;
use Core\Identity\Presentation\Console\Commands\IssueApiKeyCommand;
use Core\Identity\Presentation\Console\Commands\RevokeApiKeyCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bounded context: identity
 * Responsibility: Organizations, Projects, API keys, machine authentication.
 * Delivery lot: Lot 1
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationRepository::class, EloquentOrganizationRepository::class);
        $this->app->bind(ProjectRepository::class, EloquentProjectRepository::class);
        $this->app->bind(ApiKeyRepository::class, EloquentApiKeyRepository::class);
        $this->app->bind(ApiKeySecretGenerator::class, RandomApiKeySecretGenerator::class);
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
            ]);
        }
    }
}
