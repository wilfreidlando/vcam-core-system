<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * The page of offers: what the project sells, and whether this customer
 * may move to it alone.
 */
final readonly class PortalPlans
{
    /**
     * @param list<PortalPlanOption> $options public plans, cheapest first
     * @param string|null $changeableSubscriptionId the contract a change would apply to, when the project allows it
     */
    public function __construct(
        public array $options,
        public ?string $changeableSubscriptionId,
        public bool $canSubscribe = false,
        public string $formNonce = '',
    ) {}

    public function canChange(): bool
    {
        return $this->changeableSubscriptionId !== null;
    }
}
