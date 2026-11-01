<?php

declare(strict_types=1);

use Core\Export\Presentation\Http\Controllers\CreateExportController;
use Core\Export\Presentation\Http\Controllers\GetExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Export routes — registered by ExportServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| ADR-0088 §3. `POST /v1/exports` carries the `idempotency` middleware: a
| replayed request returns the export it already created instead of
| starting another build of the whole project.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::post('/exports', CreateExportController::class)
            ->middleware('idempotency')
            ->name('exports.create');
        Route::get('/exports/{exportId}', GetExportController::class)
            ->whereUuid('exportId')
            ->name('exports.get');
    });
