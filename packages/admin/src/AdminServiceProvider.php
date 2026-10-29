<?php

declare(strict_types=1);

namespace Core\Admin;

use Core\Admin\Presentation\Console\Commands\ReconcileStuckPaymentsCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root of the `admin` bounded context: binds Domain ports to their
 * Infrastructure adapters and registers this module's migrations, routes and commands.
 *
 * Responsibility: Core Admin: operator console for projects, credentials, transactions, wallets, fee rules, providers, webhooks, audit and reconciliation.
 * Delivery lot: Lot 6 (ADR-0044 — first slice: operators/RBAC foundation, audit
 * search, webhook DLQ list+replay, payment reconciliation). This module has no
 * Domain/Application of its own — it composes other modules' Contracts ports
 * (ADR-0027: "Admin compose, il n'interroge pas directement").
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No Domain ports of its own to bind — every capability this
        // module exposes is composed from another module's Contracts.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::middleware('api')->group(__DIR__.'/../routes/api.php');

        $this->registerConsoleRateLimiters();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileStuckPaymentsCommand::class,
            ]);
        }
    }

    /**
     * Écart #17 (annexe sécurité 2026-10-29, G-12) — le plafond des actions sensibles
     * de la console web (création, rotation, révocation, invitation, effacement,
     * approbation). Deux seaux, comme `operator-auth` : par opérateur (son jeton de
     * session, car le plafond s'évalue avant ou après la résolution de l'opérateur)
     * ET par IP. Une écriture légitime est humaine : quelques-unes par minute.
     */
    private function registerConsoleRateLimiters(): void
    {
        RateLimiter::for('console-sensitive', fn (Request $request): array => [
            Limit::perMinute(20)->by('console-sensitive-operator:'.self::operatorBucket($request)),
            Limit::perMinute(60)->by('console-sensitive-ip:'.$request->ip()),
        ]);

        // La ressaisie du code TOTP est un oracle de code : plafond serré, par opérateur.
        RateLimiter::for('console-step-up', fn (Request $request): array => [
            Limit::perMinute(5)->by('console-step-up-operator:'.self::operatorBucket($request)),
            Limit::perMinute(20)->by('console-step-up-ip:'.$request->ip()),
        ]);
    }

    private static function operatorBucket(Request $request): string
    {
        $token = $request->hasSession() ? $request->session()->get('operator_bearer_token') : null;

        return is_string($token) && $token !== ''
            ? hash('sha256', $token)
            : 'anonymous:'.$request->ip();
    }
}
