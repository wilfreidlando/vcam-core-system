<?php

declare(strict_types=1);

namespace Core\Portal;

use Core\Portal\Application\Support\PortalLinks;
use Core\Portal\Application\UseCase\ManagePortalSettingsUseCase;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Domain\Port\PortalSessionRepository;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Portal\Infrastructure\Persistence\DatabasePortalSessionRepository;
use Core\Portal\Infrastructure\Persistence\DatabasePortalSettingsRepository;
use Core\Portal\Infrastructure\Security\HmacSecretHasher;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PortalSettingsRepository::class, DatabasePortalSettingsRepository::class);
        $this->app->bind(PortalSessionRepository::class, DatabasePortalSessionRepository::class);
        $this->app->bind(ManagePortalSettings::class, ManagePortalSettingsUseCase::class);

        $this->app->singleton(SecretHasher::class, static fn (): SecretHasher => new HmacSecretHasher(config()->string('app.key')));
        $this->app->singleton(PortalLinks::class, static fn (): PortalLinks => new PortalLinks(config()->string('portal.base_url', config()->string('app.url'))));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'portal');

        // One limiter per door, all keyed so that one noisy customer or
        // server cannot starve the others (ADR-0085 sets the final policy).
        RateLimiter::for('portal-enter', static fn (Request $request): Limit => Limit::perMinute(20)->by('portal-enter:'.$request->ip()));
        RateLimiter::for('portal', static function (Request $request): Limit {
            $cookie = $request->cookies->get($request->isSecure() ? '__Host-portal' : 'portal');

            return Limit::perMinute(120)->by('portal:'.(is_string($cookie) && $cookie !== '' ? hash('sha256', $cookie) : $request->ip()));
        });
        RateLimiter::for('portal-sessions', static fn (Request $request): Limit => Limit::perMinute(300)->by('portal-sessions:'.$request->bearerToken()));

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');
        Route::group([], __DIR__.'/../routes/web.php');
    }
}
