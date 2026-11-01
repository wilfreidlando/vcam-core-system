<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The console's read model of the usage of a project (ADR-0080 §2: « clients
 * au-delà de 80 % »). The scope is **required** (ADR-0069 §2, G16): a project
 * outside it yields nothing.
 */
interface ListUsage
{
    /**
     * @param string|null $filter `over80` (80 % and more) or `exceeded` (at the limit), null for all
     * @return UsagePage<UsageCounterRow>
     */
    public function counters(string $projectId, ?string $filter, ?string $customerRef, ?string $cursor, int $limit, AccessScope $scope): UsagePage;

    /**
     * The journal, newest first.
     *
     * @return UsagePage<UsageRecordRow>
     */
    public function records(string $projectId, ?string $meter, ?string $customerRef, ?string $cursor, int $limit, AccessScope $scope): UsagePage;
}
