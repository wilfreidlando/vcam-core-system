<?php

declare(strict_types=1);

use Core\Payment\Presentation\Http\Controllers\MyCoolPayWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * Inbound webhook routes for payment providers.
 *
 * Each provider is mounted under `/webhooks/{provider}` so the routes
 * are easy to enumerate for an operator inspecting the routing table.
 * Authentication is intentionally absent: webhooks are server-to-server
 * calls authenticated by the provider's own signature scheme (see
 * `WebhookSignatureVerifier` in `core/support`).
 */
Route::prefix('webhooks')->group(function (): void {
    Route::post('my-coolpay', MyCoolPayWebhookController::class)
        ->name('payments.webhooks.my-coolpay');
});
