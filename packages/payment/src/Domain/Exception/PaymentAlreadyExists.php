<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Exception;

use RuntimeException;

/**
 * Raised when the `(project_id, idempotency_key)` unique constraint
 * rejects an insert — two concurrent calls to `StartPaymentUseCase` with
 * the same idempotency key raced, and this one lost. Not an error from
 * the caller's point of view: the use case catches this and re-reads the
 * payment the winner created.
 *
 * A genuinely simultaneous race here means both requests already reached
 * the provider before either insert landed — a known, documented
 * trade-off (see `StartPaymentUseCase`), not a gap this exception papers
 * over.
 */
final class PaymentAlreadyExists extends RuntimeException {}
