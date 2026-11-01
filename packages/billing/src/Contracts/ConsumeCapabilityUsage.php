<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

/**
 * ADR-0074 §5 — the door the other modules of the Core use to be metered.
 *
 * The project declares in `billing_settings.meter_mappings` which
 * capability feeds which meter (`{"notification.whatsapp": "whatsapp_messages"}`).
 * A capability without a mapping is **not metered**: the decision says so
 * and nothing is counted.
 */
interface ConsumeCapabilityUsage
{
    public function consume(ConsumeCapabilityUsageCommand $command): UsageDecision;
}
