<?php

declare(strict_types=1);

use Core\Notification\Presentation\Http\Controllers\GetNotificationStatusController;
use Core\Notification\Presentation\Http\Controllers\SendNotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notification routes — registered by NotificationServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| Self-service only (ADR-0041 §1) — nothing in the Core triggers a
| notification automatically. `POST /v1/notifications` carries the
| `idempotency` middleware per spec §13 ("toutes les mutations
| monétaires et d'envoi"), even though it isn't money and isn't G7.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::post('/notifications', SendNotificationController::class)
            ->middleware('idempotency')
            ->name('notifications.send');
        Route::get('/notifications/{notificationId}', GetNotificationStatusController::class)->name('notifications.status');
    });
