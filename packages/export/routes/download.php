<?php

declare(strict_types=1);

use Core\Export\Presentation\Http\Controllers\DownloadExportController;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The signed download of an export (ADR-0088 §3)
|--------------------------------------------------------------------------
|
| Outside the `api` group and outside `/v1`: it is not an API call but a
| link, valid fifteen minutes and usable ONCE. An altered or expired link is
| a 403.
|
| Two doors, one archive:
|   - `/exports/{exportId}/download` — the link an integration fetches from
|     its server with its API key (`GET /v1/exports/{id}`): the signature is
|     the only authentication;
|   - `/exports/{exportId}/download/{operatorId}` — the link the CONSOLE
|     hands to an operator (audit de sécurité 2026-10-29 #16). The operator is
|     part of the signed path, and the route demands the console session of
|     that very operator: a link that leaks (history, Referer, a message)
|     is useless in any other browser, and in the hands of another operator.
*/

Route::middleware(['signed', 'throttle:30,1'])
    ->get('/exports/{exportId}/download', DownloadExportController::class)
    ->whereUuid('exportId')
    ->name('exports.download');

Route::middleware(['signed', EncryptCookies::class, StartSession::class, 'operator.session', 'throttle:30,1'])
    ->get('/exports/{exportId}/download/{operatorId}', [DownloadExportController::class, 'forOperator'])
    ->whereUuid(['exportId', 'operatorId'])
    ->name('exports.download.operator');
