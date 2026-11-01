<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Domain\Model\Plan;
use Core\Billing\Domain\Port\PlanRepository;

/**
 * The **listing** half of the plan repository — ADR-0071 §5, the
 * catalogue of a project, page after page (ADR-0088).
 */
interface PlanDirectory extends PlanRepository
{
    /**
     * The plans of a project, page after page.
     *
     * @param string|null $status a canonical `PlanStatus` value
     *                            ('draft', 'active', 'archived'); `null`
     *                            lists every status
     * @param string|null $cursor the cursor of the page before
     * @return array{items: list<Plan>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $status,
        ?string $cursor,
        int $limit,
    ): array;
}
