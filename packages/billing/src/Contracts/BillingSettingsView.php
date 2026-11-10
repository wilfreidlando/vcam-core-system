<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0071 §4, ADR-0072 §2, ADR-0074 §5–6, ADR-0094 §4, ADR-0119 §1 — what
 * a project chose for its commerce.
 */
final readonly class BillingSettingsView
{
    /**
     * @param array<string, string> $meterMappings capability => meter
     * @param list<SupplementPackView> $supplementPacks the packs the project offers to its customers
     */
    public function __construct(
        public string $timezone,
        public string $defaultCurrency,
        public int $renewalLeadDays,
        public int $incompleteExpiryHours,
        public string $invoicePrefix,
        public int $defaultTaxRateBps,
        public int $manualReceiptApprovalThresholdMinor,
        public array $meterMappings,
        public bool $refundFailedUsage,
        public bool $requireCustomerRef,
        public array $supplementPacks = [],
    ) {}
}
