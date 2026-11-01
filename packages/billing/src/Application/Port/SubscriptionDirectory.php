<?php

declare(strict_types=1);

namespace Core\Billing\Application\Port;

use Core\Billing\Domain\Model\Subscription;
use Core\Billing\Domain\Port\SubscriptionRepository;
use DateTimeImmutable;

/**
 * The **listing** half of the subscription repository: the contracts of a
 * project (ADR-0072 §7) and the candidates of the scheduler (ADR-0072
 * §8).
 */
interface SubscriptionDirectory extends SubscriptionRepository
{
    /**
     * The contracts of a project, page after page.
     *
     * @param string|null $customerId narrowed to one customer
     * @param string|null $status a canonical `SubscriptionStatus`
     *                            value; `null` lists every status
     * @param string|null $cursor the cursor of the page before
     * @return array{items: list<Subscription>, nextCursor: ?string}
     */
    public function listByProject(
        string $projectId,
        ?string $customerId,
        ?string $status,
        ?string $cursor,
        int $limit,
    ): array;

    /**
     * The contracts whose next step may be due — the scheduler's
     * candidates (ADR-0072 §8).
     *
     * The set is deliberately broad: every contract that is not in a final
     * state and whose current period ends within the **maximum** renewal
     * lead (90 days — the ceiling `billing_settings.renewal_lead_days`
     * allows), plus every `incomplete` contract, whose expiry does not
     * wait for the end of its period.
     *
     * The precise due-ness is decided by the use case, per project: the
     * lead time and the incomplete expiry are settings of the project
     * (ADR-0071 §4).
     *
     * @return list<Subscription>
     */
    public function findDueForAdvance(DateTimeImmutable $now, int $limit): array;
}
