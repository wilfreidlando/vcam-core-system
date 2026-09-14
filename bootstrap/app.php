<?php

declare(strict_types=1);

use Core\Support\Http\Middleware\CorrelationId;
use Core\Support\Http\ProblemDetails;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request carries a correlation id, inbound or generated, so that
        // logs, events, webhooks and audit entries are traceable end to end.
        $middleware->append(CorrelationId::class);

        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        // Wires the named 'api' limiter (AppServiceProvider::configureRateLimiting)
        // into the group — quotas per project/environment, spec technique §14.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One error format across the whole Core, which has no HTML surface:
        // RFC 7807 problem+json, always carrying the correlation id.
        $exceptions->render(function (Throwable $e) {
            return match (true) {
                $e instanceof ValidationException => ProblemDetails::make(
                    'validation-failed',
                    'The request payload is invalid',
                    422,
                    'One or more fields failed validation.',
                    ['errors' => $e->errors()],
                ),
                $e instanceof AuthenticationException => ProblemDetails::unauthenticated(
                    'Valid project credentials are required.',
                ),
                $e instanceof HttpExceptionInterface => ProblemDetails::make(
                    'http-error',
                    'Request could not be completed',
                    $e->getStatusCode(),
                    $e->getMessage() !== '' ? $e->getMessage() : null,
                ),
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
