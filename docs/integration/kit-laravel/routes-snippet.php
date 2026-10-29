<?php

declare(strict_types=1);

// Paste into routes/web.php (the webhook route must NOT sit in the `web` group's CSRF).

use App\Billing\BillingController;
use App\Billing\CoreWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    // Add your own authorisation: only who may manage billing should see this.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
});

// Excluded from CSRF in bootstrap/app.php:
//   $middleware->validateCsrfTokens(except: ['webhooks/core']);
Route::post('/webhooks/core', CoreWebhookController::class);

// Gate the billable part of your app:
//   Route::middleware(['auth', 'subscribed'])->group(...);
//   Route::middleware(['auth', 'subscribed:whatsapp'])->post('/messages', ...);
