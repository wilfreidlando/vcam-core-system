<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\DatabaseIdempotencyStore;
use App\Infrastructure\DatabaseUnitOfWork;
use App\Infrastructure\ProjectIdempotencyScope;
use Core\Support\Clock;
use Core\Support\Idempotency\IdempotencyScope;
use Core\Support\Idempotency\IdempotencyStore;
use Core\Support\SystemClock;
use Core\Support\UnitOfWork;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\RouteInfo;
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
        $this->app->bind(UnitOfWork::class, DatabaseUnitOfWork::class);
        $this->app->bind(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->bind(IdempotencyScope::class, ProjectIdempotencyScope::class);
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
        $this->configureApiDocumentationTags();
    }

    /**
     * Group the OpenAPI docs by bounded context (`Core\<Module>\...`),
     * not by controller — Scramble's default tags each operation under
     * its controller's own short name (`StartPayment`, `GetPaymentStatus`,
     * one tag per endpoint), which fragments the doc instead of grouping
     * it. Derived from the namespace rather than annotated per controller
     * so a new endpoint is grouped correctly with zero extra effort
     * (governance: prefer structural over manual — docs/specs/gouvernance-anti-derive.md).
     *
     * A controller nested one level deeper than `Controllers\` — e.g.
     * `Core\Notification\Presentation\Http\Controllers\Waha\WahaWebhookController`
     * — gets a second tag from that subdirectory name, so
     * provider-specific endpoints (channel-session linking, a provider's
     * inbound webhook) group separately from a module's generic
     * endpoints within the same top-level tag. A controller directly
     * under `Controllers\` (no subdirectory) only gets the module tag.
     */
    private function configureApiDocumentationTags(): void
    {
        Scramble::resolveTagsUsing(function (RouteInfo $routeInfo): array {
            $class = $routeInfo->className();
            if ($class === null) {
                return [];
            }

            $pattern = '/^Core\\\\([A-Za-z]+)\\\\.*\\\\Controllers\\\\(?:([A-Za-z]+)\\\\)?[A-Za-z0-9_]+$/';
            if (preg_match($pattern, $class, $matches) !== 1) {
                return [];
            }

            $tags = [$matches[1]];
            if (($matches[2] ?? '') !== '') {
                $tags[] = $matches[2];
            }

            return $tags;
        });
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
