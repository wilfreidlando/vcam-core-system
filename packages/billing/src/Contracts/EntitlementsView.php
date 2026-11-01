<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §4 — what a customer may do right now, and how much is left.
 */
final readonly class EntitlementsView
{
    /**
     * @param list<EntitlementView> $entitlements
     */
    public function __construct(
        public string $customerRef,
        public bool $active,
        public ?string $planCode,
        public ?string $subscriptionId,
        public array $entitlements,
    ) {}
}
