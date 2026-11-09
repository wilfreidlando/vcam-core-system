<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\SubscriptionSupplementView;
use Core\Billing\Domain\Model\SubscriptionSupplement;

/**
 * ADR-0117 §2 — the public shape of a supplement.
 *
 * The Domain model never leaves the module (G3c): the read model is
 * built here, with the money in minor units (G5) and the instants in
 * ISO-8601 (ADR-0072 §7).
 */
final readonly class SupplementViews
{
    public static function from(
        SubscriptionSupplement $supplement,
        string $customerRef,
        ?string $invoiceNumber = null,
    ): SubscriptionSupplementView {
        return new SubscriptionSupplementView(
            id: $supplement->id,
            projectId: $supplement->projectId,
            subscriptionId: $supplement->subscriptionId,
            customerRef: $customerRef,
            meter: $supplement->meter,
            featureKey: $supplement->featureKey,
            quantity: $supplement->quantity,
            unitPriceMinor: $supplement->unitPriceMinor,
            totalMinor: $supplement->totalMinor(),
            currency: $supplement->currency,
            status: $supplement->status->value,
            invoiceId: $supplement->invoiceId,
            invoiceNumber: $invoiceNumber,
            periodId: $supplement->periodId,
            activatedAt: Iso8601::ofNullable($supplement->activatedAt),
            canceledAt: Iso8601::ofNullable($supplement->canceledAt),
            createdAt: Iso8601::of($supplement->createdAt),
            updatedAt: Iso8601::of($supplement->updatedAt),
        );
    }
}
