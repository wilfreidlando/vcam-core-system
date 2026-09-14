<?php

declare(strict_types=1);

use Core\Identity\Presentation\Http\Controllers\WhoAmIController;
use Core\Identity\Presentation\Http\Middleware\ResolveProjectMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity routes — registered by IdentityServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
*/

Route::middleware([ResolveProjectMiddleware::class])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/whoami', WhoAmIController::class)->name('identity.whoami');
    });
