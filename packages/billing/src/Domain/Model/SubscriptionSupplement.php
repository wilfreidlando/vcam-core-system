<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use DateTimeImmutable;

/**
 * ADR-0117 §2 — a supplement: units bought **on top of** the limit of a
 * metered feature of a contract.
 *
 * A supplement is **not** a subscription and **not** a second counter
 * (ADR-0074 §3): it adds a quantity to the limit that the plan version
 * already carries, on the very meter of that feature. The counter, its
 * window and its journal stay the ones of the contract — the customer
 * who buys a pack of fifty messages keeps his plan, his period and his
 * hundred consumed messages.
 *
 * The terms are **frozen** at the purchase, like the terms of a contract
 * (ADR-0072 §1): the meter, the quantity, the unit price and the
 * currency never move afterwards, even if the plan changes.
 *
 * The life of a supplement is three states (ADR-0117 §2): `pending`
 * while its invoice is not settled, `active` once it is, `canceled` when
 * that invoice was annulled. Only `active` grants the allowance — a
 * purchase that was not paid for is not a right.
 */
final class SubscriptionSupplement
{
    public function __construct(
        public readonly string $id,
        public readonly string $projectId,
        public readonly string $subscriptionId,
        public readonly string $customerId,
        /** The metered feature this supplement extends (ADR-0074 §3). */
        public readonly string $meter,
        /** The key of that feature in the plan version of the contract. */
        public readonly string $featureKey,
        /** The number of units added to the limit. */
        public readonly int $quantity,
        /** The price of one unit, in minor units of `currency` (G5). */
        public readonly int $unitPriceMinor,
        public readonly string $currency,
        public SubscriptionSupplementStatus $status,
        /** The invoice that carries this supplement (ADR-0117 §4). */
        public ?string $invoiceId,
        /**
         * The period this supplement covers — the one in progress when
         * the money arrived (ADR-0121). It is set again at the activation:
         * a payment that lands after the end of the period the purchase
         * was made in covers the period the contract is in then.
         */
        public ?string $periodId,
        public ?DateTimeImmutable $activatedAt,
        public ?DateTimeImmutable $canceledAt,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        /**
         * ADR-0119 §6 — la clé du geste qui a produit ce supplément.
         * Un rejeu de la même clé retrouve cette ligne au lieu d'en
         * créer une deuxième.
         */
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * A supplement is bought: the terms are checked once, here, and
     * frozen afterwards.
     *
     * @throws InvalidSubscriptionSupplement when the quantity is not a
     *                                       positive number of units, or
     *                                       the price is negative (G5)
     */
    public static function purchase(
        string $id,
        string $projectId,
        string $subscriptionId,
        string $customerId,
        string $meter,
        string $featureKey,
        int $quantity,
        int $unitPriceMinor,
        string $currency,
        ?string $periodId,
        DateTimeImmutable $createdAt,
        ?string $idempotencyKey = null,
    ): self {
        if ($quantity <= 0) {
            throw InvalidSubscriptionSupplement::becauseQuantityNotPositive();
        }

        if ($unitPriceMinor < 0) {
            throw InvalidSubscriptionSupplement::becausePriceNegative();
        }

        return new self(
            id: $id,
            projectId: $projectId,
            subscriptionId: $subscriptionId,
            customerId: $customerId,
            meter: $meter,
            featureKey: $featureKey,
            quantity: $quantity,
            unitPriceMinor: $unitPriceMinor,
            currency: strtoupper($currency),
            status: SubscriptionSupplementStatus::Pending,
            invoiceId: null,
            periodId: $periodId,
            activatedAt: null,
            canceledAt: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * ADR-0117 §2 — the money arrived: the supplement grants its
     * allowance from this instant.
     *
     * ADR-0121 — the allowance covers the **period the contract is in
     * when the money arrives**, not the one it was in when the order was
     * placed: a payment that lands late covers the period running then,
     * so the customer is never left having paid for nothing.
     */
    public function activate(DateTimeImmutable $at, ?string $periodId = null): void
    {
        if ($this->status !== SubscriptionSupplementStatus::Pending) {
            throw InvalidSubscriptionSupplement::becauseNotPending($this->status->value);
        }

        $this->status = SubscriptionSupplementStatus::Active;
        $this->activatedAt = $at;
        $this->periodId = $periodId ?? $this->periodId;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0117 §2 — the invoice was annulled before any money arrived:
     * the supplement never granted anything.
     */
    public function cancel(DateTimeImmutable $at): void
    {
        if ($this->status !== SubscriptionSupplementStatus::Pending) {
            throw InvalidSubscriptionSupplement::becauseNotPending($this->status->value);
        }

        $this->status = SubscriptionSupplementStatus::Canceled;
        $this->canceledAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * ADR-0117 §4 — the invoice that carries this supplement is known
     * once it is issued.
     */
    public function attachInvoice(string $invoiceId, DateTimeImmutable $at): void
    {
        $this->invoiceId = $invoiceId;
        $this->updatedAt = $at;
    }

    /**
     * What the supplement costs, in minor units of `currency` (G5).
     */
    public function totalMinor(): int
    {
        return $this->quantity * $this->unitPriceMinor;
    }

    /**
     * Does it add to the limit of its meter right now?
     */
    public function grantsAllowance(): bool
    {
        return $this->status->grantsAllowance();
    }
}
