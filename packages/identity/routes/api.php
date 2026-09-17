<?php

declare(strict_types=1);

use Core\Identity\Presentation\Http\Controllers\ConfirmOperatorMfaController;
use Core\Identity\Presentation\Http\Controllers\EnrollOperatorMfaController;
use Core\Identity\Presentation\Http\Controllers\LoginOperatorController;
use Core\Identity\Presentation\Http\Controllers\OperatorLogoutController;
use Core\Identity\Presentation\Http\Controllers\VerifyOperatorMfaController;
use Core\Identity\Presentation\Http\Controllers\WhoAmIController;
use Core\Identity\Presentation\Http\Controllers\WhoAmIOperatorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity routes — registered by IdentityServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| 'project.auth' is the alias bootstrap/app.php binds to
| ResolveProjectMiddleware (ADR-0033 §3) — referenced by string here too,
| not by class, so this file stays the pattern every other module's routes
| follow. 'operator.auth' (ADR-0044) is the same pattern for the platform
| operator plane.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/whoami', WhoAmIController::class)->name('identity.whoami');
    });

Route::prefix('v1/operators')
    ->group(function (): void {
        // 'operator-auth' (AppServiceProvider::configureRateLimiting) is
        // deliberately tighter than the generic 'api' limiter — these
        // four endpoints are public/unauthenticated and gate the
        // platform-admin plane, so they need their own ceiling against
        // online password/TOTP guessing.
        Route::middleware(['throttle:operator-auth'])->group(function (): void {
            Route::post('/login', LoginOperatorController::class)->name('operators.login');
            Route::post('/mfa/enroll', EnrollOperatorMfaController::class)->name('operators.mfa.enroll');
            Route::post('/mfa/confirm', ConfirmOperatorMfaController::class)->name('operators.mfa.confirm');
            Route::post('/mfa/verify', VerifyOperatorMfaController::class)->name('operators.mfa.verify');
        });

        Route::middleware(['operator.auth'])->group(function (): void {
            Route::get('/me', WhoAmIOperatorController::class)->name('operators.whoami');
            Route::post('/logout', OperatorLogoutController::class)->name('operators.logout');
        });
    });
