<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

final readonly class SubscriptionPeriodPage
{
    /**
     * @param list<SubscriptionPeriodView> $items the periods of one contract, oldest first
     */
    public function __construct(
        public array $items,
    ) {}
}
