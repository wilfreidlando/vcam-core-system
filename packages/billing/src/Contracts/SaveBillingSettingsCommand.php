<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

final readonly class SaveBillingSettingsCommand
{
    /**
     * @param array<string, string> $meterMappings capability => meter
     * @param string $actor "operator:<uuid>"
     */
    public function __construct(
        public string $projectId,
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
        public string $actor,
        public ?string $correlationId = null,
    ) {}
}
