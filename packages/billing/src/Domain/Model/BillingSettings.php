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
    public function __construct(
        public string $projectId,
        public string $timezone,
        public string $defaultCurrency,
        public int $renewalLeadDays,
        public int $incompleteExpiryHours,
    ) {}

    /**
     * The settings of a project that has not changed anything: Douala for
     * the timezone, XAF for the currency, seven days of lead and
     * seventy-two hours of incomplete expiry.
     */
    public static function defaultsFor(string $projectId): self
    {
        return new self(
            projectId: $projectId,
            timezone: 'Africa/Douala',
            defaultCurrency: 'XAF',
            renewalLeadDays: 7,
            incompleteExpiryHours: 72,
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
