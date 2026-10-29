<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;
use InvalidArgumentException;

/**
 * ADR-0088 §2 — the usage journal of one project, page after page,
 * **oldest first**, ordered by the stable `(recorded_at, id)` pair: a
 * traversal during concurrent writes neither repeats nor skips a line
 * recorded before it started.
 *
 * The scope is a **required** parameter (ADR-0069 §2): a project outside
 * it yields an empty page.
 */
interface ListUsageJournal
{
    /**
     * @return UsagePage<UsageRecordRow>
     *
     * @throws InvalidArgumentException the cursor is not one this listing issued
     */
    public function list(ListUsageJournalCommand $command, AccessScope $scope): UsagePage;
}
