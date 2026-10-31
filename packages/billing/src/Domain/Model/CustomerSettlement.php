<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use DateTimeImmutable;

/**
 * ADR-0073 §2, §4 — one settlement of a customer, as the domain sees it.
 *
 * The settlement is not an entity of its own: it is a **reading** of two
 * tables — the online attempt and the offline receipt — brought
 * together because the operator asks about them together (« what did
 * this customer pay me? »). It therefore carries no behaviour, only the
 * facts the console shows.
 *
 * The `id` is the identifier of the source row, without its prefix: the
 * prefix that makes it unique across both kinds belongs to the read
 * model of `Contracts/` (G3c).
 *
 * The amount is in minor units (G5).
 */
final class CustomerSettlement
{
    public function __construct(
        /** The identifier of the source row — a uuid, whatever the kind. */
        public readonly string $id,
        public readonly CustomerSettlementKind $kind,
        public readonly string $invoiceId,
        /** The number of the invoice — `null` while it is still a draft. */
        public readonly ?string $invoiceNumber,
        /** 'online' for a payment, the receipt's own method otherwise. */
        public readonly string $method,
        /** The receipt's reference, or the id of the payment. */
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        /** The canonical status of the settlement, as a plain string. */
        public readonly string $status,
        /** When the settlement happened. */
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}
