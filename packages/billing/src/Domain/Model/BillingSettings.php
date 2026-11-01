<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidBillingSettings;
use DateTimeZone;
use Exception;

/**
 * ADR-0071 §4 and ADR-0072 §2 — the settings a project owns.
 *
 * The timezone is the one the periods are computed in; the storage stays
 * UTC. The renewal lead says how early the next period is prepared and
 * its invoice issued (ADR-0072 §4); the incomplete expiry says how long
 * an unpaid first invoice keeps a contract alive (ADR-0072 §2).
 */
final readonly class BillingSettings
{
    /**
     * @param array<string, mixed> $documentBranding the free marks of the printed documents
     * @param array<string, string> $meterMappings which capability of the Core feeds which meter (ADR-0074 §5)
     */
    public function __construct(
        public string $projectId,
        public string $timezone,
        public string $defaultCurrency,
        public int $renewalLeadDays,
        public int $incompleteExpiryHours,
        public string $invoicePrefix = 'FACT',
        public int $defaultTaxRateBps = 0,
        public int $manualReceiptApprovalThresholdMinor = 0,
        public bool $retainInvoiceIdentity = true,
        public array $documentBranding = [],
        public array $meterMappings = [],
        public bool $refundFailedUsage = true,
        public bool $requireCustomerRef = false,
    ) {}

    /**
     * The meter a capability of the Core feeds, or null when the project
     * declared none — a capability without a mapping is never counted
     * (ADR-0074 §5).
     */
    public function meterFor(string $capability): ?string
    {
        return $this->meterMappings[$capability] ?? null;
    }

    /**
     * The settings of a project that has not changed anything: Douala for
     * the timezone, XAF for the currency, seven days of lead and
     * seventy-two hours of incomplete expiry.
     *
     * The settings of the invoices (ADR-0094 §4) have their defaults too:
     * `FACT` as the prefix of the number, no tax, no threshold of
     * approval, and the identity frozen on the invoices **kept** at the
     * erasure of a customer.
     */
    public static function defaultsFor(string $projectId): self
    {
        return new self(
            projectId: $projectId,
            timezone: 'Africa/Douala',
            defaultCurrency: 'XAF',
            renewalLeadDays: 7,
            incompleteExpiryHours: 72,
            invoicePrefix: 'FACT',
            defaultTaxRateBps: 0,
            manualReceiptApprovalThresholdMinor: 0,
            retainInvoiceIdentity: true,
            documentBranding: [],
            meterMappings: [],
            refundFailedUsage: true,
            requireCustomerRef: false,
        );
    }

    /**
     * The timezone of the project, as the arithmetic of the periods needs
     * it (ADR-0071 §4).
     *
     * An unknown timezone is refused here: a contract whose periods cannot
     * be computed is worse than a settings row rejected outright.
     */
    public function zone(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->timezone);
        } catch (Exception) {
            throw InvalidBillingSettings::becauseUnknownTimezone($this->timezone);
        }
    }
}
