<?php

declare(strict_types=1);

use Core\Identity\Presentation\Http\Controllers\WhoAmIController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity routes — registered by IdentityServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| 'project.auth' is the alias bootstrap/app.php binds to
| ResolveProjectMiddleware (ADR-0033 §3) — referenced by string here too,
| not by class, so this file stays the pattern every other module's routes
| follow.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/whoami', WhoAmIController::class)->name('identity.whoami');
    });
