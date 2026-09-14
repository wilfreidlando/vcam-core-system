<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The Core is an API platform: it has no public web UI. The Core Admin
| operator console (Lot 6) registers its own routes from the `core/admin`
| package. Everything a consuming product needs lives under /api.
|
*/

Route::get('/', fn (): JsonResponse => new JsonResponse([
    'service' => 'Enterprise Core System',
    'documentation' => '/docs/api',
]));
