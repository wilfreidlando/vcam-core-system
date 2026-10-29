<?php

declare(strict_types=1);

use Core\Portal\Presentation\Http\Controllers\DirectLoginController;
use Core\Portal\Presentation\Http\Controllers\PortalPagesController;
use Core\Portal\Presentation\Http\Middleware\AuthenticatePortal;
use Core\Portal\Presentation\Http\Middleware\PortalSecurityHeaders;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal pages — registered by PortalServiceProvider (ADR-0075)
|--------------------------------------------------------------------------
|
| No `web` group on purpose: the portal has its own cookie, its own CSRF
| token and its own headers; the console's session stack must not touch it.
| A route that is not listed here does not exist.
*/

Route::middleware([PortalSecurityHeaders::class])
    ->prefix('portal')
    ->group(function (): void {
        // The one-time link: exchanged for the cookie, then gone.
        Route::get('/enter/{token}', [PortalPagesController::class, 'enter'])
            ->where('token', '[A-Za-z0-9_-]{20,128}')
            ->middleware('throttle:portal-enter')
            ->name('portal.enter');

        // ADR-0075 §2 — the login by code, off unless the project turned it on.
        Route::middleware('throttle:portal-login')->group(function (): void {
            Route::get('/{projectId}/login', [DirectLoginController::class, 'form'])->whereUuid('projectId')->name('portal.login');
            Route::post('/{projectId}/login', [DirectLoginController::class, 'send'])->whereUuid('projectId')->name('portal.login.send');
            Route::post('/{projectId}/login/verify', [DirectLoginController::class, 'verify'])->whereUuid('projectId')->name('portal.login.verify');
        });

        Route::get('/theme.css', [PortalPagesController::class, 'theme'])->middleware('throttle:portal-theme')->name('portal.theme');

        Route::middleware(['throttle:portal', AuthenticatePortal::class])->group(function (): void {
            Route::get('/', [PortalPagesController::class, 'home'])->name('portal.home');
            Route::get('/subscriptions', [PortalPagesController::class, 'subscriptions'])->name('portal.subscriptions');
            Route::post('/subscriptions/{subscriptionId}/cancel', [PortalPagesController::class, 'cancel'])
                ->whereUuid('subscriptionId')
                ->name('portal.subscriptions.cancel');
            Route::post('/subscriptions/{subscriptionId}/change-plan', [PortalPagesController::class, 'changePlan'])
                ->whereUuid('subscriptionId')
                ->name('portal.subscriptions.change-plan');
            Route::post('/plans/{planCode}/subscribe', [PortalPagesController::class, 'subscribe'])
                ->where('planCode', '[a-z0-9][a-z0-9_-]{0,63}')
                ->name('portal.plans.subscribe');
            Route::post('/subscriptions/{subscriptionId}/keep', [PortalPagesController::class, 'keepRenewing'])->whereUuid('subscriptionId')->name('portal.subscriptions.keep');
            Route::post('/subscriptions/{subscriptionId}/renew', [PortalPagesController::class, 'renew'])->whereUuid('subscriptionId')->name('portal.subscriptions.renew');
            Route::get('/plans', [PortalPagesController::class, 'plans'])->name('portal.plans');
            Route::get('/payments', [PortalPagesController::class, 'payments'])->name('portal.payments');
            Route::get('/profile', [PortalPagesController::class, 'profile'])->name('portal.profile');
            Route::get('/invoices', [PortalPagesController::class, 'invoices'])->name('portal.invoices');
            Route::get('/invoices/{invoiceId}', [PortalPagesController::class, 'invoice'])->whereUuid('invoiceId')->name('portal.invoice');
            Route::post('/invoices/{invoiceId}/pay', [PortalPagesController::class, 'pay'])->whereUuid('invoiceId')->name('portal.invoice.pay');
            Route::get('/usage', [PortalPagesController::class, 'usage'])->name('portal.usage');
            Route::post('/logout', [PortalPagesController::class, 'logout'])->name('portal.logout');
        });
    });
