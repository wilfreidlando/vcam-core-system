<?php

declare(strict_types=1);

// Paste into routes/web.php. The webhook route must NOT sit in the `web` group's CSRF.

use App\Core\Billing\BillingController;
use App\Core\Webhooks\CoreWebhookController;
use Illuminate\Support\Facades\Route;

// One endpoint for everything the Core tells you. Excluded from CSRF in bootstrap/app.php:
//   $middleware->validateCsrfTokens(except: ['webhooks/core']);
Route::post('/webhooks/core', CoreWebhookController::class);

// --- Only if you sell subscriptions through the Core (SaaS commerce) ---------------------------
Route::middleware(['auth'])->group(function (): void {
    // Add your own authorisation: only who may manage billing should see this.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
});

// Gate the billable part of your app:
//   Route::middleware(['auth', 'subscribed'])->group(...);
//   Route::middleware(['auth', 'subscribed:whatsapp'])->post('/messages', ...);
