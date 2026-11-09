<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\ResolveOperatorSession;
use App\Http\View\Composers\AdminBreadcrumbComposer;
use App\Http\View\Composers\AdminSidebarComposer;
use App\Infrastructure\DatabaseIdempotencyStore;
use App\Infrastructure\DatabaseUnitOfWork;
use App\Infrastructure\Guide\GuideCatalog;
use App\Infrastructure\Guide\GuideRenderer;
use App\Infrastructure\ProjectIdempotencyScope;
use App\Listeners\CoreNoticeListener;
use App\OpenApi\ExceptionProblemResponseExtension;
use App\OpenApi\ProblemDetailsStaticMethodReturnTypeExtension;
use Core\Events\Contracts\OutboxEventPublished;
use Core\Support\Clock;
use Core\Support\Http\Middleware\LimitRequestBody;
use Core\Support\Idempotency\IdempotencyScope;
use Core\Support\Idempotency\IdempotencyStore;
use Core\Support\SystemClock;
use Core\Support\UnitOfWork;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

/**
 * The application shell. It is the composition root: it may depend on the
 * bounded contexts, never the other way round.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * The CSP of the documentation pages (`/docs/api`). The two third-party
     * bundles it loads are named explicitly; everything else is refused.
     * The pages carry inline scripts (the theme watcher, the CSRF header
     * interceptor), hence `'unsafe-inline'` — the alternative, a nonce per
     * script, is part of the CSP work described in ADR-0102.
     */
    private const DOCS_CSP = "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; "
        ."default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; "
        ."style-src 'self' 'unsafe-inline' https://unpkg.com; "
        ."img-src 'self' data: https:; connect-src 'self' https://proxy.scalar.com";

    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(UnitOfWork::class, DatabaseUnitOfWork::class);
        $this->app->bind(IdempotencyStore::class, DatabaseIdempotencyStore::class);
        $this->app->bind(IdempotencyScope::class, ProjectIdempotencyScope::class);

        // ADR-0113 — the operator guide. The catalogue reads the Markdown
        // of `docs/manuel/parcours/`; the root is a path, so the binding
        // is explicit: the container cannot guess a string. A singleton
        // because the guide does not change while a request is served.
        $this->app->singleton(GuideCatalog::class, static fn (): GuideCatalog => new GuideCatalog(
            base_path('docs/manuel/parcours'),
        ));
        $this->app->singleton(GuideRenderer::class, static fn (Application $app): GuideRenderer => new GuideRenderer(
            $app->make(GuideCatalog::class),
        ));
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

        // ADR-0118 — the Core's notices to its customers, on the events the
        // outbox relay republishes. The composition lives in the shell (the
        // composition root); the sending, the journal and the idempotence
        // belong to the `notification` module.
        Event::listen(OutboxEventPublished::class, CoreNoticeListener::class);

        // Load the admin web UI routes under the `web` middleware group.
        // They live in routes/admin.php (separate from the platform's
        // bare-bones routes/web.php which only exposes the service
        // banner). The 'operator.session' middleware gates them.
        Route::middleware('web')
            ->group(base_path('routes/admin.php'));

        // ADR-0069 §2 — the console sidebar reads its entries and badges
        // through a composer, never through a port called in the template.
        View::composer('layouts.admin', AdminSidebarComposer::class);
        // The trail above the title: derived from the route, the project's name read through a scoped port.
        View::composer('layouts.admin', AdminBreadcrumbComposer::class);

        // ADR-0067: the Horizon dashboard (its routes run under `web`, so
        // the console session is available) opens to the operator signed in
        // to the admin console who holds `queues:manage` — PlatformAdmin
        // only. Left as Horizon's default (open) in `local`.
        Horizon::auth(static fn (Request $request): bool => app()->environment('local')
            || (app(ResolveOperatorSession::class)->currentOperator()?->hasPermission('queues:manage') ?? false));

        $this->configureRateLimiting();
        $this->configureRequestBodyLimit();
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

        // `IdempotencyHeaderExtension` is registered through
        // `config('scramble.extensions')` rather than `Scramble::registerExtension()`:
        // Scramble's package boot snapshots `Scramble::$extensions` before
        // any app provider runs, so anything added from `boot()` is too
        // late and silently never fires. See config/scramble.php.

        // Same caveat: append the outbound-event schema transformer to the
        // document transformer list from boot. The package has already
        // registered its two defaults; this adds ours to the same
        // chain. If a future Scramble version snapshots its transformer
        // list at boot, this should move to `config('scramble.extensions')`.
        Scramble::configure()->withDocumentTransformers([
            \App\OpenApi\EventSchemasDocumentTransformer::class,
        ]);
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
     * Audit de sécurité 2026-10-29 #13 — the application bounds the body it
     * accepts (1 Mio, JSON nested on 20 levels at most) at the head of the
     * `api` group, before throttling, authentication or any validator reads
     * it. The alias lets a route outside the group opt in explicitly.
     */
    private function configureRequestBodyLimit(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('body.limit', LimitRequestBody::class);
        $router->prependMiddlewareToGroup('api', LimitRequestBody::class);
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
     * credentials, so it stays public in local and staging. In PRODUCTION
     * (audit de sécurité 2026-10-29 #14) it is no longer an open map of the
     * whole public surface: only an operator signed in to the console who
     * holds `audit:read` may open it. `/docs/admin` additionally relies on
     * the `operator.session` middleware attached to its routes above.
     *
     * Whatever the environment, these pages are never framed and never leak
     * their address through the `Referer` (they load third-party scripts).
     */
    private function configureApiDocumentationAccess(): void
    {
        // Nullable, unused first parameter: Laravel's Gate never invokes an
        // ability callback for a guest (no authenticated user — true for
        // every request here, this Core has no Auth::user()) unless the
        // callback's first parameter explicitly allows null, regardless of
        // whether it's actually read.
        Gate::define('viewApiDocs', static function (mixed $user = null): bool {
            if (! app()->isProduction()) {
                return true;
            }

            return app(ResolveOperatorSession::class)->currentOperator()?->hasPermission('audit:read') ?? false;
        });

        Event::listen(RequestHandled::class, static function (RequestHandled $handled): void {
            if (! $handled->request->is('docs/*')) {
                return;
            }

            // Audit 2026-10-29 #14 / G-1 — the documentation pages load two
            // third-party bundles on the API's own origin. The CSP names
            // exactly those two hosts and nothing else: no inline script
            // beyond what the page declares, no frame, no plugin, no
            // form target elsewhere. `integrity` (in the views) pins the
            // content of each bundle cryptographically.
            $handled->response->headers->set('Content-Security-Policy', self::DOCS_CSP);
            $handled->response->headers->set('X-Frame-Options', 'DENY');
            $handled->response->headers->set('Referrer-Policy', 'no-referrer');
            $handled->response->headers->set('X-Content-Type-Options', 'nosniff');
            // The documentation describes the API: it must not sit in a
            // cache (browser or proxy) between a revision and the next.
            $handled->response->headers->set('Cache-Control', 'no-store, private');
        });
    }

    /**
     * Quotas are per credential and per environment (spec technique §14).
     * Until the project-resolution middleware lands (Lot 1) the limiter falls
     * back to the API key prefix, then the client address.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): array {
            $bearer = $request->bearerToken();

            // Audit 2026-10-03 §2.6: keyed on the bearer alone, a client
            // sending a fresh random bearer per request got a fresh bucket
            // every time. A per-IP ceiling applies on top, so unauthenticated
            // or invalid-key traffic is bounded too.
            $limits = [Limit::perMinute(600)->by('ip:'.$request->ip())];

            if ($bearer !== null) {
                $limits[] = Limit::perMinute(300)->by('key:'.substr(hash('sha256', $bearer), 0, 32));
            }

            return $limits;
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

        // ADR-0069 §4 — accepting an invitation is public. For an account
        // that already exists, it checks that account's current password:
        // without a per-invitation ceiling, whoever sent the invitation
        // could test the invitee's password from as many IPs as they like.
        // Two limits apply together: per IP, and per invitation (keyed on
        // the token's hash — one invitation, one invitee). A request without
        // a token shares one bucket, which only throttles malformed calls.
        RateLimiter::for('invitation-accept', function (Request $request): array {
            $token = $request->string('token')->value();

            return [
                Limit::perMinute(10)->by('invitation-accept-ip:'.$request->ip()),
                Limit::perMinute(5)->by('invitation-accept-token:'.hash('sha256', $token !== '' ? $token : 'none')),
            ];
        });
    }
}
