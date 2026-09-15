<?php

declare(strict_types=1);

use Core\Notification\Presentation\Http\Controllers\Waha\WahaWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * Inbound webhook routes for notification channel providers — mirrors
 * `packages/payment/routes/webhooks.php`. Each provider is mounted under
 * `/webhooks/{provider}`. Authentication is provider-specific: WAHA is
 * checked against a shared secret header in `WahaWebhookController`
 * itself (ADR-0042 §8), not a route middleware.
 */
Route::prefix('webhooks')->group(function (): void {
    Route::post('whatsapp-waha', WahaWebhookController::class)
        ->name('notifications.webhooks.whatsapp-waha');
});
