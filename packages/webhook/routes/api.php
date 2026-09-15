<?php

declare(strict_types=1);

use Core\Webhook\Presentation\Http\Controllers\DeactivateWebhookEndpointController;
use Core\Webhook\Presentation\Http\Controllers\ListWebhookEndpointsController;
use Core\Webhook\Presentation\Http\Controllers\RegisterWebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook routes — registered by WebhookServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| Endpoint registration is self-service (ADR-0040 §6) — unlike API key
| issuance, it is not a credential and carries no `idempotency` middleware:
| none of these routes mutate money (G7 does not apply).
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::post('/webhook-endpoints', RegisterWebhookEndpointController::class)->name('webhook-endpoints.register');
        Route::get('/webhook-endpoints', ListWebhookEndpointsController::class)->name('webhook-endpoints.list');
        Route::delete('/webhook-endpoints/{endpointId}', DeactivateWebhookEndpointController::class)
            ->name('webhook-endpoints.deactivate');
    });
