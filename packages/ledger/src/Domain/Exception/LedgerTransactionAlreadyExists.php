<?php

declare(strict_types=1);

namespace Core\Ledger\Domain\Exception;

use RuntimeException;

/**
 * Raised when `(project_id, idempotency_key)`'s unique constraint is hit
 * on insert — a genuine race between two concurrent `post()` calls with
 * the same idempotency key, both of which read "not yet posted" under
 * READ COMMITTED before either committed (security review finding: this
 * used to bubble up as an unhandled `UniqueConstraintViolationException`
 * — a 500 — instead of the idempotent-replay result the caller actually
 * asked for; no double-post ever occurred, only the response was wrong).
 */
final class LedgerTransactionAlreadyExists extends RuntimeException {}
