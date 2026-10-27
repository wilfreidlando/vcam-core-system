<?php

declare(strict_types=1);

namespace Core\Billing\Application\Dto;

/**
 * What one pass of the scheduler did — the counters the console command
 * displays (ADR-0072 §8).
 */
final class AdvanceSubscriptionsReport
{
    /** The contracts the pass looked at. */
    public int $examined = 0;

    /** The next periods prepared before their deadline (ADR-0072 §4). */
    public int $renewalsPrepared = 0;

    /** The contracts that rolled over to a new period. */
    public int $renewed = 0;

    /** The contracts that started running. */
    public int $activated = 0;

    /** The contracts that became late. */
    public int $pastDue = 0;

    /** The contracts that ended. */
    public int $expired = 0;

    /** The contracts cancelled at the end of their period. */
    public int $canceled = 0;

    /**
     * The contracts the pass could not advance. They are counted and
     * logged, never fatal: the pass continues with the next one.
     */
    public int $failed = 0;
}
