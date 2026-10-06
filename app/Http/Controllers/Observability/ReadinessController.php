<?php

declare(strict_types=1);

namespace App\Http\Controllers\Observability;

use Core\Events\Contracts\OutboxHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * ADR-0059 — readiness, distinct from Laravel's `/up` liveness probe:
 * `/up` says the PHP process answers, `/ready` says the Core can actually
 * do its job (database, Valkey, outbox relay keeping up). 503 when any
 * check fails, so a load balancer or monitor can act on it. Answers only
 * check names and booleans — never connection strings or error messages.
 */
final class ReadinessController
{
    /** An outbox row older than this means the relay is stuck. */
    private const OUTBOX_MAX_LAG_SECONDS = 300;

    public function __invoke(OutboxHealth $outbox): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn (): bool => DB::selectOne('SELECT 1 AS ok') !== null),
            'valkey' => $this->check(static fn (): bool => Redis::connection()->command('ping') !== false),
            'outbox_relay' => $this->check(static fn (): bool => $outbox->snapshot()->oldestUnpublishedAgeSeconds < self::OUTBOX_MAX_LAG_SECONDS),
        ];

        $ready = ! in_array(false, $checks, true);

        return new JsonResponse(['status' => $ready ? 'ready' : 'degraded', 'checks' => $checks], $ready ? 200 : 503);
    }

    /**
     * @param callable(): bool $probe
     */
    private function check(callable $probe): bool
    {
        try {
            return $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
