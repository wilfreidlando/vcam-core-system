<?php

declare(strict_types=1);

namespace Core\Support\Idempotency;

use RuntimeException;

/**
 * Raised when `IdempotencyStore::lock()` races against another request
 * for the same `(project_id, key)` — the `RequireIdempotencyKey`
 * middleware treats this as a concurrent replay and rejects it with a
 * 409, never as a server error.
 */
final class IdempotencyKeyAlreadyLocked extends RuntimeException {}
