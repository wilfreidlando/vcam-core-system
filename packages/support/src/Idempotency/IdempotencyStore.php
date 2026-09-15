<?php

declare(strict_types=1);

namespace Core\Support\Idempotency;

/**
 * Port for the generic HTTP-level idempotency mechanism (ADR-0025),
 * distinct from any module's own application-level idempotency (e.g.
 * `Core\Ledger\Contracts\LedgerPoster`'s `idempotencyKey`, or
 * `Core\Payment\Contracts\StartPaymentCommand`'s). This one exists to
 * let `RequireIdempotencyKey` cache and replay a whole HTTP response,
 * regardless of which module the route belongs to.
 *
 * Scoped by `(projectId, key)` — two Projects may reuse the same key
 * with no collision. Persistence-free signature; the implementation
 * touches the database, so it lives in the application shell
 * (`app/Infrastructure/`), never in this package (G3d).
 */
interface IdempotencyStore
{
    public function find(string $projectId, string $key): ?IdempotencyRecord;

    /**
     * Reserves the key for this request. The caller must have already
     * checked `find()` returns null immediately before calling this —
     * a race between the two is possible under concurrent requests;
     * implementations must reject a duplicate lock rather than
     * overwrite one.
     *
     * @throws IdempotencyKeyAlreadyLocked when another request reserved
     *                                     this `(projectId, key)` first.
     */
    public function lock(string $projectId, string $key, string $requestHash): void;

    /**
     * @param array<string, mixed> $responseBody
     */
    public function complete(string $projectId, string $key, int $responseStatus, array $responseBody): void;
}
