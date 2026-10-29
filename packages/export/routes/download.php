<?php

declare(strict_types=1);

use Core\Export\Presentation\Http\Controllers\DownloadExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The signed download of an export (ADR-0088 §3)
|--------------------------------------------------------------------------
|
| Outside the `api` group and outside `/v1`: it is not an API call but a
| link, authenticated by its signature only (`signed`), valid fifteen
| minutes. An altered or expired link is a 403.
*/

Route::middleware(['signed', 'throttle:30,1'])
    ->get('/exports/{exportId}/download', DownloadExportController::class)
    ->whereUuid('exportId')
    ->name('exports.download');
