<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Admin web UI routes.
 *
 * Mounted under the `web` middleware group (session + cookie auth, CSRF
 * protection). The `operator.session` middleware reads the Sanctum
 * cookie set by the login + MFA flow and binds `OperatorContext` for the
 * request.
 *
 * Routes that DON'T need authentication (login + MFA challenge + logout)
 * sit OUTSIDE the `operator.session` group. Everything else is
 * gated. Missing auth -> redirect to /admin/login (handled inside
 * each controller via a shared `EnsureAdminAuthenticated` helper that
 * throws a custom exception caught by the framework exception
 * handler).
 */

Route::middleware(['web'])->prefix('admin')->name('admin.')->group(function (): void {

    // Unauthenticated: login + MFA challenge + logout.
    // 'operator-auth' (AppServiceProvider::configureRateLimiting) is the
    // same tighter limiter the JSON API's equivalent routes carry — this
    // web plane used to have zero throttling at all (review finding,
    // Lot 6 follow-up).
    Route::middleware(['throttle:operator-auth'])->group(function (): void {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login.show');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::get('mfa/enroll', [AuthController::class, 'showMfaEnroll'])->name('mfa.enroll.show');
        Route::post('mfa/enroll', [AuthController::class, 'confirmMfaEnrollment'])->name('mfa.confirm');
        Route::get('mfa/verify', [AuthController::class, 'showMfaVerify'])->name('mfa.verify.show');
        Route::post('mfa/verify', [AuthController::class, 'verifyMfa'])->name('mfa.verify');
    });
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // Authenticated: the whole operator console.
    Route::middleware(['operator.session'])->group(function (): void {

        Route::get('/', [DashboardController::class, 'show'])->name('dashboard');

        Route::prefix('audit-log')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\AuditLogController::class, 'index'])
                ->name('audit-log.index');
        });

        Route::prefix('organizations')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\OrganizationsController::class, 'index'])
                ->name('organizations.index');
            Route::get('create', [App\Http\Controllers\Admin\OrganizationsController::class, 'create'])
                ->name('organizations.create');
            Route::post('/', [App\Http\Controllers\Admin\OrganizationsController::class, 'store'])
                ->name('organizations.store');
        });

        Route::prefix('projects')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\ProjectsController::class, 'index'])
                ->name('projects.index');
            // Must be registered BEFORE `GET projects/{projectId}` below —
            // Laravel matches route patterns in registration order, so a
            // parameterised route registered first would greedily capture
            // `/admin/projects/create` as `{projectId}='create'` and hit
            // `show()`, which then 422s on UUID validation.
            Route::get('create', [App\Http\Controllers\Admin\ProjectsController::class, 'create'])
                ->name('projects.create');
            Route::post('/', [App\Http\Controllers\Admin\ProjectsController::class, 'store'])
                ->name('projects.store');
            Route::get('{projectId}', [App\Http\Controllers\Admin\ProjectsController::class, 'show'])
                ->name('projects.show');
            Route::get('{projectId}/wallets', [App\Http\Controllers\Admin\WalletsController::class, 'index'])
                ->name('projects.wallets');
        });

        Route::prefix('payments')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\PaymentsController::class, 'index'])
                ->name('payments.index');
            Route::get('{payment}', [App\Http\Controllers\Admin\PaymentsController::class, 'show'])
                ->name('payments.show');
        });

        Route::prefix('webhook-deliveries')->group(function (): void {
            Route::get('dead', [App\Http\Controllers\Admin\WebhookDeliveriesController::class, 'index'])
                ->name('webhook-deliveries.dead');
            Route::post('{delivery}/replay', [App\Http\Controllers\Admin\WebhookDeliveriesController::class, 'replay'])
                ->name('webhook-deliveries.replay');
        });

        Route::prefix('reconciliation')->group(function (): void {
            Route::get('new', [App\Http\Controllers\Admin\ReconciliationController::class, 'new'])
                ->name('reconciliation.new');
            Route::post('/', [App\Http\Controllers\Admin\ReconciliationController::class, 'run'])
                ->name('reconciliation.run');
        });

        // ADR-0050: operator self-service — mirrors packages/admin's JSON
        // /v1/admin/operators endpoints for the console web plane.
        Route::prefix('operators')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\OperatorsController::class, 'index'])
                ->name('operators.index');
            Route::get('create', [App\Http\Controllers\Admin\OperatorsController::class, 'create'])
                ->name('operators.create');
            Route::post('/', [App\Http\Controllers\Admin\OperatorsController::class, 'store'])
                ->name('operators.store');
            Route::post('{operatorId}/revoke', [App\Http\Controllers\Admin\OperatorsController::class, 'revoke'])
                ->name('operators.revoke');
            // Literal segments (`create`, `{operatorId}/edit`) always win
            // over a parameter route for an exact match in Laravel, so
            // this doesn't collide with `operators/create` above.
            Route::get('{operatorId}/edit', [App\Http\Controllers\Admin\OperatorsController::class, 'edit'])
                ->name('operators.edit');
            Route::put('{operatorId}', [App\Http\Controllers\Admin\OperatorsController::class, 'update'])
                ->name('operators.update');
        });
    });
});
