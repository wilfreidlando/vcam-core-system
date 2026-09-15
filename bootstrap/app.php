<?php

declare(strict_types=1);

use Core\Identity\Presentation\Http\Middleware\ResolveProjectMiddleware;
use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Domain\Exception\PaymentNotFound;
use Core\Support\Http\Middleware\CorrelationId;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use Core\Wallet\Domain\Exception\WalletNotFound;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotDead;
use Core\Webhook\Domain\Exception\WebhookDeliveryNotFound;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
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

        // ADR-0033 §3: every module's routes reference this alias, never
        // Identity's Presentation class directly — that would violate G3c.
        // The composition root is the one place allowed to bind the string
        // to the class.
        $middleware->alias([
            'project.auth' => ResolveProjectMiddleware::class,
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
                $e instanceof WalletNotFound => ProblemDetails::make(
                    'wallet-not-found',
                    'Wallet not found',
                    404,
                    $e->getMessage(),
                ),
                $e instanceof PaymentNotFound => ProblemDetails::make(
                    'payment-not-found',
                    'Payment not found',
                    404,
                    $e->getMessage(),
                ),
                $e instanceof NoProviderForChannelException => ProblemDetails::make(
                    'no-provider-for-channel',
                    'No provider is configured for this channel',
                    422,
                    $e->getMessage(),
                ),
                $e instanceof WebhookEndpointNotFound => ProblemDetails::make(
                    'webhook-endpoint-not-found',
                    'Webhook endpoint not found',
                    404,
                    $e->getMessage(),
                ),
                $e instanceof WebhookDeliveryNotFound => ProblemDetails::make(
                    'webhook-delivery-not-found',
                    'Webhook delivery not found',
                    404,
                    $e->getMessage(),
                ),
                $e instanceof WebhookDeliveryNotDead => ProblemDetails::make(
                    'webhook-delivery-not-dead',
                    'Only a dead webhook delivery can be replayed',
                    409,
                    $e->getMessage(),
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
