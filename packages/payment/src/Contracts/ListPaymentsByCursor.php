<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Support\Access\AccessScope;
use InvalidArgumentException;

/**
 * ADR-0088 §2 — the payments of one project, page after page, ordered by
 * the stable `(created_at, id)` pair so that a traversal during concurrent
 * insertions neither repeats nor skips a row older than its start.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a project outside
 * it yields an empty page.
 */
interface ListPaymentsByCursor
{
    /**
     * @throws InvalidArgumentException the cursor is not one this listing issued
     */
    public function list(ListPaymentsByCursorCommand $command, AccessScope $scope): PaymentCursorPage;
}
