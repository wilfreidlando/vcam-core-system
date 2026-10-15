<?php

declare(strict_types=1);

use App\Http\Controllers\Observability\MetricsController;
use App\Http\Controllers\Observability\ReadinessController;
use App\Http\ExceptionProblemMapping;
use App\Http\Middleware\RecordHttpMetrics;
use Core\Identity\Presentation\Http\Middleware\ResolveOperatorMiddleware;
use Core\Identity\Presentation\Http\Middleware\ResolveProjectMiddleware;
use Core\Support\Http\Middleware\CorrelationId;
use Core\Support\Http\Middleware\LogHttpExchange;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\Middleware\TraceHttpRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The published contract uses /v1/... paths (spec technique §13,
        // ADR-0028), not Laravel's default /api/v1/....
        apiPrefix: '',
        // ADR-0059: probes and the Prometheus scrape — outside every
        // middleware group (no session, no auth middleware: each guards
        // itself).
        then: function (): void {
            Route::get('/ready', ReadinessController::class)->name('observability.ready');
            Route::get('/metrics', MetricsController::class)->name('observability.metrics');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request carries a correlation id, inbound or generated, so that
        // logs, events, webhooks and audit entries are traceable end to end.
        $middleware->append(CorrelationId::class);
        // ADR-0059: request/response pair in the logs, after CorrelationId so
        // both lines carry the correlation and trace ids.
        $middleware->append(LogHttpExchange::class);
        $middleware->append(TraceHttpRequest::class);
        $middleware->append(RecordHttpMetrics::class);

        // Trusted reverse proxies: see config/trustedproxy.php (audit
        // 2026-10-03 §2.2). Laravel's global TrustProxies middleware reads
        // `trustedproxy.proxies` itself; ADR-0064 swaps it for a subclass that
        // only honours forwarded headers coming from this project's sidecar.
        $middleware->replace(
            Illuminate\Http\Middleware\TrustProxies::class,
            App\Http\Middleware\TrustProxiesFromSidecar::class,
        );

        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        // Wires the named 'api' limiter (AppServiceProvider::configureRateLimiting)
        // into the group — quotas per project/environment, spec technique §14.
        $middleware->throttleApi();

        // ADR-0033 §3: every module's routes reference this alias, never
        // Identity's Presentation class directly — that would violate G3c.
        // The composition root is the one place allowed to bind the string
        // to the class.
        $middleware->alias([
            'project.auth' => ResolveProjectMiddleware::class,
            // ADR-0044: the platform operator plane, parallel to project.auth.
            'operator.auth' => ResolveOperatorMiddleware::class,
            // Admin web UI: reads the Sanctum cookie set by ResolveOperatorSession
            // (instead of the bearer header used by operator.auth on the API plane).
            // The two middlewares share the same use case internally.
            'operator.session' => App\Http\Middleware\ResolveOperatorSession::class,
            // G7: every monetary mutation route applies this (ADR-0025).
            'idempotency' => RequireIdempotencyKey::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // ADR-0040 §1: the single reader of event_outbox. Overlap
        // protection matters here specifically — two concurrent runs
        // would both read the same unpublished rows before either marks
        // them, republishing each one twice.
        $schedule->command('events:relay')->everyMinute()->withoutOverlapping();

        // ADR-0044: background reconciliation for missed webhooks (spec
        // technique §10). Same overlap protection reasoning as events:relay.
        // ADR-0067: every minute — on staging, the provider's callbacks go to
        // production, so this is the only way a payment gets confirmed.
        $schedule->command('admin:reconcile-stuck-payments')->everyMinute()->withoutOverlapping();

        // Audit 2026-10-03 §4.3: re-dispatch webhook deliveries whose job was lost.
        $schedule->command('webhook:sweep')->everyFiveMinutes()->withoutOverlapping();

        // Audit 2026-10-03 §4.6: bounded retention. Class names as strings —
        // the composition root never imports a module's Infrastructure.
        $schedule->command('model:prune', ['--model' => [
            'Core\\Events\\Infrastructure\\Persistence\\Eloquent\\EventOutboxRecord',
            'Core\\Webhook\\Infrastructure\\Persistence\\Eloquent\\WebhookDeliveryRecord',
            'Core\\Payment\\Infrastructure\\Persistence\\Eloquent\\PaymentTimelineEntryRecord',
        ]])->dailyAt('03:17')->withoutOverlapping();

        // ADR-0062 / D5: notification content (phone numbers…) kept 90 days.
        $schedule->command('notification:redact-expired')->dailyAt('03:27')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One error format across the whole Core's JSON surface: RFC 7807
        // problem+json, always carrying the correlation id. The admin web
        // console (routes/admin.php) is the one HTML surface — its form
        // submissions need Laravel's normal redirect-back-with-$errors
        // behaviour, not a raw JSON body (review finding, Lot 6
        // follow-up: every web form's @error() block was dead code
        // because ValidationException always rendered as problem+json,
        // regardless of caller).
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ValidationException && ! $request->expectsJson()) {
                // Falls through to ValidationException's own render()
                // (redirect back with $errors flashed to the session).
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ProblemDetails::make(
                    'validation-failed',
                    'The request payload is invalid',
                    422,
                    'One or more fields failed validation.',
                    ['errors' => $e->errors()],
                ),
                // Laravel's own message is an internal guard name, not
                // something a caller should see — a fixed detail string,
                // unlike every mapped exception below (which use their
                // own `getMessage()` verbatim), so this stays a special
                // case rather than joining `ExceptionProblemMapping`.
                $e instanceof AuthenticationException => ProblemDetails::unauthenticated(
                    'Valid project credentials are required.',
                ),
                $e instanceof HttpExceptionInterface => ProblemDetails::make(
                    'http-error',
                    'Request could not be completed',
                    $e->getStatusCode(),
                    $e->getMessage() !== '' ? $e->getMessage() : null,
                ),
                // Every other domain-level rejection this Core raises —
                // one shared table (`ExceptionProblemMapping`) instead of
                // 22 near-identical arms, so it can't silently drift from
                // what `App\OpenApi\ExceptionProblemResponseExtension`
                // documents on `/docs/api`/`/docs/admin`.
                ($mapped = ExceptionProblemMapping::toResponse($e)) !== null => $mapped,
                // Never leak internals in production; keep the debug page locally.
                config('app.debug') === true => null,
                default => ProblemDetails::make(
                    'internal-error',
                    'Internal server error',
                    500,
                    'The error has been logged. Quote the correlation id when contacting support.',
                ),
            };
        });
    })->create();
