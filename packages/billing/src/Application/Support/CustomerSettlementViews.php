<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\CustomerSettlementView;
use Core\Billing\Domain\Model\CustomerSettlement;

/**
 * The mapping from the settlement of the domain to the read model of
 * `Contracts/` (G3c — the domain model itself never leaves the module).
 *
 * The kind leaves as its `->value`, never as the enum; the date leaves
 * as an ISO-8601 string, in UTC, whatever the timezone of the project is.
 */
final readonly class CustomerSettlementViews
{
    public static function from(CustomerSettlement $settlement): CustomerSettlementView
    {
        return new CustomerSettlementView(
            // The prefix is what makes the identifier unique across the
            // two kinds: the console shows one list, and it must be able
            // to tell a payment from a receipt.
            id: $settlement->kind->value.':'.$settlement->id,
            kind: $settlement->kind->value,
            invoiceId: $settlement->invoiceId,
            invoiceNumber: $settlement->invoiceNumber,
            method: $settlement->method,
            reference: $settlement->reference,
            amountMinor: $settlement->amountMinor,
            currency: $settlement->currency,
            status: $settlement->status,
            occurredAt: Iso8601::of($settlement->occurredAt),
        );
    }
}
