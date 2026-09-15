<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Exception;

use RuntimeException;

/**
 * Race on the `(project_id, idempotency_key)` unique constraint — mirrors
 * `Core\Payment\Domain\Exception\PaymentAlreadyExists`.
 */
final class NotificationAlreadyExists extends RuntimeException {}
