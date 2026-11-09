<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Port;

use Core\Billing\Domain\Model\SubscriptionSupplement;

/**
 * ADR-0117 §2 — the persistence port of the supplements of a contract.
 */
interface SubscriptionSupplementRepository
{
    /**
     * The supplement of its id, whichever project owns it — the caller
     * carries the tenant check.
     */
    public function findById(string $id): ?SubscriptionSupplement;

    /**
     * Every supplement of a contract, whatever its state.
     *
     * @return list<SubscriptionSupplement>
     */
    public function listBySubscription(string $subscriptionId): array;

    /**
     * The supplements of a contract that grant their allowance — the
     * `active` ones (ADR-0117 §2). This is what the effective limit is
     * made of.
     *
     * @return list<SubscriptionSupplement>
     */
    public function listActiveBySubscription(string $subscriptionId): array;

    /**
     * The supplements carried by an invoice — the ones that become
     * active when that invoice is settled, and the ones that are
     * annulled with it (ADR-0117 §4).
     *
     * @return list<SubscriptionSupplement>
     */
    public function listByInvoice(string $invoiceId): array;

    /**
     * ADR-0119 §6 — the supplement a gesture already produced.
     *
     * The key is the one of the gesture (the portal derives it from its
     * form, server-side). A replay finds the supplement here and returns
     * it, instead of buying a second time.
     */
    public function findByIdempotencyKey(string $projectId, string $idempotencyKey): ?SubscriptionSupplement;

    /**
     * Persists a supplement, created or updated.
     */
    public function save(SubscriptionSupplement $supplement): void;
}
