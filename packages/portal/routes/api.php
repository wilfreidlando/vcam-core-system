<?php

declare(strict_types=1);

use Core\Portal\Presentation\Http\Controllers\OpenPortalSessionController;
use Core\Portal\Presentation\Http\Controllers\PortalSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal API — registered by PortalServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| The server-to-server side of the portal (ADR-0075): a project opens a
| session for one of its customers and sets the look of its portal.
|
| Opening a session is not a money route (G7): it moves nothing, and the
| link it returns is single-use by construction.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::post('/portal/sessions', OpenPortalSessionController::class)
            ->middleware('throttle:portal-sessions')
            ->name('portal.sessions.open');
        Route::get('/portal/settings', [PortalSettingsController::class, 'show'])->name('portal.settings.show');
        Route::put('/portal/settings', [PortalSettingsController::class, 'update'])->name('portal.settings.update');
    });
