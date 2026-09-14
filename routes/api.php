<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Platform routes
|--------------------------------------------------------------------------
|
| Cross-cutting platform endpoints only. Capability routes are NOT registered
| here: each bounded context publishes its own route file from its service
| provider (ADR-0018), so adding a capability never edits a shared file and
| never creates a merge conflict between teams.
|
*/

// Readiness, as opposed to Laravel's built-in liveness probe at /up.
// Liveness answers "is the process alive"; readiness answers "can it serve
// traffic" — which for the Core means the database and the queue backend are
// reachable. An orchestrator must not route payments to an instance that
// cannot write to the ledger.
Route::get('/health/ready', function (): JsonResponse {
    $checks = [
        'database' => static function (): void {
            DB::connection()->getPdo();
        },
        'cache' => static function (): void {
            app(RedisFactory::class)->connection()->command('ping');
        },
    ];

    $results = [];
    $healthy = true;

    foreach ($checks as $name => $check) {
        try {
            $check();
            $results[$name] = 'ok';
        } catch (Throwable $e) {
            $results[$name] = 'failing';
            $healthy = false;
        }
    }

    return new JsonResponse(
        ['status' => $healthy ? 'ready' : 'degraded', 'checks' => $results],
        $healthy ? 200 : 503,
    );
})->name('health.ready');
