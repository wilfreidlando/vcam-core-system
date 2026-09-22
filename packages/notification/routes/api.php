<?php

declare(strict_types=1);

use Core\Notification\Presentation\Http\Controllers\GetNotificationStatusController;
use Core\Notification\Presentation\Http\Controllers\SendFileNotificationController;
use Core\Notification\Presentation\Http\Controllers\SendImageNotificationController;
use Core\Notification\Presentation\Http\Controllers\SendTextNotificationController;
use Core\Notification\Presentation\Http\Controllers\Waha\GetChannelSessionQrCodeController;
use Core\Notification\Presentation\Http\Controllers\Waha\GetChannelSessionScreenshotController;
use Core\Notification\Presentation\Http\Controllers\Waha\RequestChannelSessionPairingCodeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification routes — registered by NotificationServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| Self-service only (ADR-0041 §1) — nothing in the Core triggers a
| notification automatically.
|
| `POST /v1/notifications/{text,image,file}` (ADR-0043) — one endpoint
| per content type, content supplied inline (no pre-registered
| template). Routing by `channel` is unchanged: adding a second provider
| behind a channel, or an entirely new channel, is a config + adapter
| change, never an endpoint change. Each carries the `idempotency`
| middleware per spec §13 ("toutes les mutations monétaires et
| d'envoi"), even though it isn't money and isn't G7.
|
| The `notification-channel-sessions` routes (ADR-0042) are a thin proxy
| to whichever provider is routed for `{channel}` — the Core persists
| nothing about a session, `{sessionRef}` is caller-supplied and
| forwarded to the provider as-is. No `idempotency` middleware: none of
| these are a "send".
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::prefix('notifications')->group(function (): void {
            Route::post('/text', SendTextNotificationController::class)
                ->middleware('idempotency')
                ->name('notifications.send-text');
            Route::post('/image', SendImageNotificationController::class)
                ->middleware('idempotency')
                ->name('notifications.send-image');
            Route::post('/file', SendFileNotificationController::class)
                ->middleware('idempotency')
                ->name('notifications.send-file');
            Route::get('/{notificationId}', GetNotificationStatusController::class)
                ->whereUuid('notificationId')
                ->name('notifications.status');
        });

        Route::prefix('notification-channel-sessions/{channel}/{sessionRef}')
            ->where(['channel' => '[a-z][a-z0-9_]*', 'sessionRef' => '[A-Za-z0-9_-]+'])
            ->group(function (): void {
                Route::get('/qr', GetChannelSessionQrCodeController::class)->name('notification-channel-sessions.qr');
                Route::get('/screenshot', GetChannelSessionScreenshotController::class)->name('notification-channel-sessions.screenshot');
                Route::post('/pairing-code', RequestChannelSessionPairingCodeController::class)->name('notification-channel-sessions.pairing-code');
            });
    });
