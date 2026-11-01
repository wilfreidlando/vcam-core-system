<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

/**
 * ADR-0074 §5 — The metered customer is missing or unknown: nothing was written and nothing was sent
 * (no notification row, no provider call). The caller may retry later
 * with the same idempotency key.
 */
final class NotificationCustomerInvalid extends RuntimeException {}
