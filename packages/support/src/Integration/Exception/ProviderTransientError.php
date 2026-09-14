<?php

declare(strict_types=1);

namespace Core\Support\Integration\Exception;

/**
 * Transient failure: network error, 5xx, 429 — a retry may succeed.
 *
 * The `ExternalApiCaller` retries transient failures per the
 * `RetryPolicy` of the provider. After the budget is exhausted, this
 * exception is re-thrown to the caller, who decides whether to escalate,
 * park the operation for an outbox/queue (Lot 3+), or surface it.
 */
final class ProviderTransientError extends ProviderException {}
