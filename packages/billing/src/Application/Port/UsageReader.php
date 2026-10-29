<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Contracts\UsageCounterRow;
use Core\Billing\Contracts\UsagePage;
use Core\Billing\Contracts\UsageRecordRow;

/**
 * The read side of the usage tables, for the console (a persistence concern:
 * it joins customers to show their reference).
 */
interface UsageReader
{
    /**
     * @return UsagePage<UsageCounterRow>
     */
    public function counters(string $projectId, ?string $filter, ?string $customerRef, ?string $cursor, int $limit): UsagePage;

    /**
     * @return UsagePage<UsageRecordRow>
     */
    public function records(string $projectId, ?string $meter, ?string $customerRef, ?string $cursor, int $limit): UsagePage;
}
