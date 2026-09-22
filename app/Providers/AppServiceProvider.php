<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\DatabaseIdempotencyStore;
use App\Infrastructure\DatabaseUnitOfWork;
use App\Infrastructure\ProjectIdempotencyScope;
use App\OpenApi\ExceptionProblemResponseExtension;
use App\OpenApi\ProblemDetailsStaticMethodReturnTypeExtension;
use Core\Support\Clock;
use Core\Support\Idempotency\IdempotencyScope;
use Core\Support\Idempotency\IdempotencyStore;
use Core\Support\SystemClock;
use Core\Support\UnitOfWork;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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

        // Load the admin web UI routes under the `web` middleware group.
        // They live in routes/admin.php (separate from the platform's
        // bare-bones routes/web.php which only exposes the service
        // banner). The 'operator.session' middleware gates them.
        Route::middleware('web')
            ->group(base_path('routes/admin.php'));

        $this->configureRateLimiting();
        $this->configureApiDocumentationTags();
        $this->configureAdminApiDocumentation();
        $this->configureApiDocumentationAccess();

        // Registers on BOTH documents (`/docs/api` and `/docs/admin` —
        // Scramble::$extensions is global, not per-registered-API), so a
        // controller under either plane documenting a mapped exception
        // via `@throws` gets the same problem+json response shape.
        Scramble::registerExtension(ExceptionProblemResponseExtension::class);

        // Covers the OTHER half of this Core's error responses: a scope
        // or permission check that returns `ProblemDetails::forbidden()`/
        // `unauthenticated()` inline never throws at all, so the
        // extension above never applies — without this, nearly every
        // 401/403 in the API documented as an empty `{type: array}`.
        Scramble::registerExtension(ProblemDetailsStaticMethodReturnTypeExtension::class);
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
     * The operator/admin console plane (`v1/admin/*`, `v1/operators/*`)
     * gets its own OpenAPI document, `/docs/admin` (+ `/docs/admin.json`)
     * — separate from `/docs/api`, the third-party integration surface
     * (ADR-0049). Mixing the two into one document meant a Project
     * integrator's docs listed `POST /v1/admin/payment-reconciliation-runs`
     * right next to `POST /v1/payments`, even though the former requires
     * an operator bearer token and has nothing to do with integration.
     *
     * `Scramble::registerApi()` clones the default API's config (so this
     * inherits the same renderer, dev-tools setting, etc.) then overrides
     * just `api_path` (this plane only) and `security_strategy` (operator
     * bearer, not the Project API-key format `/docs/api` documents).
     */
    private function configureAdminApiDocumentation(): void
    {
        Scramble::registerApi('admin', [
            'api_path' => [
                'include' => ['v1/admin', 'v1/operators'],
            ],
            'info' => [
                'version' => config('scramble.info.version'),
                'description' => 'Core Admin — the platform operator console\'s own API '.
                    '(operator bearer token, `Authorization: Bearer <token>`). This is '.
                    'not a third-party integration surface; see `/docs/api` for that.',
            ],
            'ui' => [
                'title' => 'Core Admin API',
            ],
            'security_strategy' => [
                MiddlewareAuthSecurityStrategy::class,
                [
                    'middleware' => ['operator.auth'],
                    'scheme' => SecurityScheme::http('bearer'),
                ],
            ],
        ])->expose(
            // @phpstan-ignore-next-line — Scramble passes $action as mixed (its own docblock), narrower than Router::get()'s declared param type.
            ui: fn (Router $router, mixed $action) => $router->get('docs/admin', $action)
                ->middleware('operator.session')
                ->name('scramble.docs.admin.ui'),
            // @phpstan-ignore-next-line — same dynamic-typing gap as the ui closure above.
            document: fn (Router $router, mixed $action) => $router->get('docs/admin.json', $action)
                ->middleware('operator.session')
                ->name('scramble.docs.admin.document'),
        );
    }

    /**
     * Scramble's own `RestrictedDocsAccess` middleware (applied to every
     * documented route via `config('scramble.middleware')`) only lets
     * `local` through automatically; anywhere else it checks this Gate and
     * 403s if it isn't defined — which it never was, so both documents
     * 403'd outside local (user-reported, first production deployment).
     *
     * `/docs/api` is the third-party integration surface (ADR-0049) — it's
     * meant to be read by a Project integrator who has no operator
     * credentials, so it's public here unconditionally. `/docs/admin`
     * instead relies on the `operator.session` middleware attached to its
     * routes above (the same mechanism every other admin console page
     * uses) — this Gate stays permissive for it too, rather than
     * duplicating a second, parallel check of "is there a valid operator
     * session" that could drift from `ResolveOperatorSession`.
     */
    private function configureApiDocumentationAccess(): void
    {
        // Nullable, unused first parameter: Laravel's Gate never invokes an
        // ability callback for a guest (no authenticated user — true for
        // every request here, this Core has no Auth::user()) unless the
        // callback's first parameter explicitly allows null, regardless of
        // whether it's actually read.
        Gate::define('viewApiDocs', fn (mixed $user = null): bool => true);
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

        // Stricter than the generic 'api' limiter above: the operator
        // login/MFA endpoints (`/v1/operators/{login,mfa/*}`) are public,
        // unauthenticated, and gate the platform-admin plane — 300/min
        // per IP is permissive enough for online password/TOTP guessing
        // (review finding, Lot 6 follow-up). Two limits apply together:
        // a per-IP ceiling, and a tighter per-credential ceiling (email
        // for /login, challenge token for the MFA steps) so an attacker
        // can't just rotate the other dimension to escape one limit.
        RateLimiter::for('operator-auth', function (Request $request): array {
            $email = $request->string('email')->value();
            $challengeToken = $request->string('challenge_token')->value();
            $credential = $email !== '' ? $email : ($challengeToken !== '' ? $challengeToken : 'none');

            return [
                Limit::perMinute(20)->by('operator-auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('operator-auth-credential:'.hash('sha256', $credential)),
            ];
        });
    }
}
