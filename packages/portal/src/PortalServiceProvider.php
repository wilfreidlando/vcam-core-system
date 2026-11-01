<?php

declare(strict_types=1);

namespace Core\Portal;

use Core\Portal\Application\Support\PortalLinks;
use Core\Portal\Application\UseCase\ManagePortalSettingsUseCase;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Domain\Port\LoginThrottle;
use Core\Portal\Domain\Port\PortalLoginCodeRepository;
use Core\Portal\Domain\Port\PortalSessionRepository;
use Core\Portal\Domain\Port\PortalSettingsRepository;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Portal\Infrastructure\Persistence\DatabasePortalLoginCodeRepository;
use Core\Portal\Infrastructure\Persistence\DatabasePortalSessionRepository;
use Core\Portal\Infrastructure\Persistence\DatabasePortalSettingsRepository;
use Core\Portal\Infrastructure\Security\CacheLoginThrottle;
use Core\Portal\Infrastructure\Security\HmacSecretHasher;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
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
        $this->app->bind(PortalLoginCodeRepository::class, DatabasePortalLoginCodeRepository::class);
        $this->app->bind(LoginThrottle::class, static fn (Application $app): LoginThrottle => new CacheLoginThrottle(
            self::limiter($app),
            perPhone: config()->integer('portal.direct_login.codes_per_phone_per_hour', 5),
            perIp: config()->integer('portal.direct_login.codes_per_ip_per_hour', 30),
            verificationsPerIp: config()->integer('portal.direct_login.verifications_per_ip_per_hour', 60),
            projectHourlyCap: config()->integer('portal.direct_login.project_hourly_cap', 200),
        ));
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
        // A coarse wall in front of the login by code; its real budgets (per
        // phone, per address, per project, per hour) are counted by LoginThrottle.
        RateLimiter::for('portal-login', static fn (Request $request): Limit => Limit::perMinute(30)->by('portal-login:'.$request->ip()));
        // The public stylesheet (a colour read from the query): every page links
        // it, so the wall is generous, but it is no longer unbounded.
        RateLimiter::for('portal-theme', static fn (Request $request): Limit => Limit::perMinute(240)->by('portal-theme:'.$request->ip()));
        RateLimiter::for('portal-sessions', static fn (Request $request): Limit => Limit::perMinute(300)->by('portal-sessions:'.$request->bearerToken()));

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');
        Route::group([], __DIR__.'/../routes/web.php');
    }

    private static function limiter(Application $app): CacheRateLimiter
    {
        /** @var CacheRateLimiter $limiter */
        $limiter = $app->make(CacheRateLimiter::class);

        return $limiter;
    }
}
