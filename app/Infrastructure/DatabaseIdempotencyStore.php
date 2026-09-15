<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Core\Support\Idempotency\IdempotencyKeyAlreadyLocked;
use Core\Support\Idempotency\IdempotencyRecord;
use Core\Support\Idempotency\IdempotencyStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Implementation of the ADR-0025 store — touches `DB`, so it lives here
 * rather than in `core/support` (G3d).
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function find(string $projectId, string $key): ?IdempotencyRecord
    {
        $row = DB::table('idempotency_keys')
            ->where('project_id', $projectId)
            ->where('key', $key)
            ->first();

        if ($row === null) {
            return null;
        }

        $requestHash = $row->request_hash;
        $responseStatus = $row->response_status;
        $responseBodyRaw = $row->response_body;

        if (! is_string($requestHash)) {
            throw new RuntimeException('idempotency_keys.request_hash was not a string.');
        }
        if ($responseStatus !== null && ! is_int($responseStatus)) {
            throw new RuntimeException('idempotency_keys.response_status was not an integer.');
        }
        if ($responseBodyRaw !== null && ! is_string($responseBodyRaw)) {
            throw new RuntimeException('idempotency_keys.response_body was not a string.');
        }

        /** @var array<string, mixed>|null $responseBody */
        $responseBody = $responseBodyRaw === null ? null : json_decode($responseBodyRaw, true);

        return new IdempotencyRecord(
            requestHash: $requestHash,
            completed: $row->completed_at !== null,
            responseStatus: $responseStatus,
            responseBody: $responseBody,
        );
    }

    public function lock(string $projectId, string $key, string $requestHash): void
    {
        try {
            // SAVEPOINT-safe — this middleware may run while a caller's
            // own transaction is already open (it isn't today, since
            // Payment's own writes happen after this returns, but the
            // pattern is proven throughout Lot 2 and costs nothing here).
            DB::transaction(function () use ($projectId, $key, $requestHash): void {
                DB::table('idempotency_keys')->insert([
                    'project_id' => $projectId,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'locked_at' => now(),
                    'completed_at' => null,
                    'response_status' => null,
                    'response_body' => null,
                    'expires_at' => now()->addHours(24),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new IdempotencyKeyAlreadyLocked;
        }
    }

    public function complete(string $projectId, string $key, int $responseStatus, array $responseBody): void
    {
        DB::table('idempotency_keys')
            ->where('project_id', $projectId)
            ->where('key', $key)
            ->update([
                'completed_at' => now(),
                'response_status' => $responseStatus,
                'response_body' => json_encode($responseBody, JSON_THROW_ON_ERROR),
            ]);
    }
}
