<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

final readonly class SaveBillingSettingsCommand
{
    /**
     * @param array<string, string> $meterMappings capability => meter
     * @param list<array{code: string, meter: string, quantity: int, unit_price_minor: int, label: string}>|null $supplementPacks the packs the project offers — `null` leaves the catalogue as it is (ADR-0119 §1)
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
        public ?array $supplementPacks = null,
    ) {}
}
