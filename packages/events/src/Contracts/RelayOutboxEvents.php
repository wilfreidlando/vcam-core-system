<?php

declare(strict_types=1);

namespace Core\Events\Contracts;

/**
 * Reads unpublished `event_outbox` rows and republishes each as an
 * `OutboxEventPublished` (ADR-0040). Bounded by `$limit` per call so a
 * scheduled run never locks an unbounded batch.
 */
interface RelayOutboxEvents
{
    /**
     * @return int the number of rows relayed in this call
     */
    public function relay(int $limit): int;
}
