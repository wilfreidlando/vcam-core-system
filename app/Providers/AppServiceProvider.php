<?php

declare(strict_types=1);

namespace App\Providers;

use Core\Support\Clock;
use Core\Support\SystemClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The application shell. It is the composition root: it may depend on the
 * bounded contexts, never the other way round.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
    }

    public function boot(): void
    {
        // Fail loudly in development when a query is issued per row, or when an
        // attribute that was never loaded is accessed. Silent N+1 queries in a
        // payment path become timeouts, and a timeout mid-payment is an
        // operational incident.
        $strict = ! app()->isProduction();

        Model::preventLazyLoading($strict);
        Model::preventAccessingMissingAttributes($strict);

        $this->configureRateLimiting();
    }

    /**
     * Quotas are per credential and per environment (spec technique §14).
     * Until the project-resolution middleware lands (Lot 1) the limiter falls
     * back to the API key prefix, then the client address.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $bearer = $request->bearerToken();

            $key = $bearer !== null
                ? 'key:'.substr(hash('sha256', $bearer), 0, 32)
                : 'ip:'.$request->ip();

            return Limit::perMinute(300)->by($key);
        });
    }
}
